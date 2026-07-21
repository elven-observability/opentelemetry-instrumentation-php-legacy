<?php

namespace Elven\Observability\PhpLegacy;

use Elven\Observability\PhpLegacy\Config\EnvConfigResolver;
use Elven\Observability\PhpLegacy\Export\OtlpHttpJsonLogExporter;
use Elven\Observability\PhpLegacy\Export\OtlpHttpJsonMetricExporter;
use Elven\Observability\PhpLegacy\Export\OtlpHttpJsonTraceExporter;
use Elven\Observability\PhpLegacy\Logs\LogsFacade;
use Elven\Observability\PhpLegacy\Metrics\MetricFacade;
use Elven\Observability\PhpLegacy\Privacy\AttributeRedactor;
use Elven\Observability\PhpLegacy\Propagation\TraceContextPropagator;
use Elven\Observability\PhpLegacy\Resource\ResourceBuilder;
use Elven\Observability\PhpLegacy\Support\ShutdownRegistry;
use Elven\Observability\PhpLegacy\Trace\NoopTracer;
use Elven\Observability\PhpLegacy\Trace\Sampler\ParentBasedTraceIdRatioSampler;
use Elven\Observability\PhpLegacy\Trace\SpanProcessor;
use Elven\Observability\PhpLegacy\Trace\Tracer;

final class Observability
{
    /**
     * Single source of truth for the library version reported in telemetry
     * (resource attribute telemetry.sdk.version and the instrumentation scope
     * version). Keep this in sync with the composer package version / git tag
     * as part of the release checklist.
     */
    const VERSION = '0.6.0';

    /** Instrumentation scope name reported on every exported signal. */
    const SCOPE_NAME = 'elven-observability-php-legacy';

    private static $handle;
    private static $tracer;
    private static $metrics;
    private static $logs;
    private static $context;
    private static $shutdownRegistered = false;
    private static $debugFingerprint;

    private function __construct()
    {
    }

    public static function init(array $config = array())
    {
        $resolved = EnvConfigResolver::resolve($config);
        self::debugDiagnostic($resolved);
        if (self::$handle instanceof ObservabilityHandle) {
            if (!$config || self::$handle->config()->fingerprint() === $resolved->fingerprint()) {
                self::refreshRootContext($resolved);
                return self::$handle;
            }

            self::$handle->shutdown();
            self::$handle = null;
            self::$tracer = null;
            self::$metrics = null;
            self::$logs = null;
        }

        $resource = ResourceBuilder::build($resolved);
        $redactor = new AttributeRedactor($resolved);
        $metricDefaults = array(
            'service_name' => $resolved->serviceName(),
            'service_namespace' => $resolved->serviceNamespace(),
            'environment' => $resolved->environment(),
        );

        $metricExporter = null;
        $traceExporter = null;
        $logExporter = null;
        $metricsEnabled = $resolved->isEnabled()
            && strtolower($resolved->metricsExporter()) !== 'none'
            && $resolved->metricsProtocol() === 'http/json';
        if ($metricsEnabled) {
            $metricExporter = new OtlpHttpJsonMetricExporter($resolved, $resource);
        }
        self::$metrics = new MetricFacade(
            $metricExporter,
            $redactor,
            $metricDefaults,
            $metricsEnabled,
            $resolved->maxMetricPointsPerRequest()
        );

        if (
            $resolved->isEnabled()
            && strtolower($resolved->tracesExporter()) !== 'none'
            && $resolved->tracesProtocol() === 'http/json'
        ) {
            $traceExporter = new OtlpHttpJsonTraceExporter($resolved, $resource);
        }
        if (
            $resolved->isEnabled()
            && strtolower($resolved->logsExporter()) !== 'none'
            && $resolved->logsProtocol() === 'http/json'
        ) {
            $logExporter = new OtlpHttpJsonLogExporter($resolved, $resource);
        }

        $spanProcessor = new SpanProcessor($traceExporter, self::$metrics, $resolved->maxSpansPerRequest());

        if ($resolved->isEnabled()) {
            $rootParent = $resolved->hasPropagator('tracecontext')
                ? (new TraceContextPropagator())->extract($_SERVER)
                : \Elven\Observability\PhpLegacy\Trace\SpanContext::invalid();
            $sampler = new ParentBasedTraceIdRatioSampler($resolved->samplerArg(), $resolved->sampler());
            self::$tracer = new Tracer(
                $sampler,
                $spanProcessor,
                $redactor,
                $rootParent,
                array(
                    'max_attributes' => $resolved->maxAttributesPerSpan(),
                    'max_attribute_length' => $resolved->maxAttributeLength(),
                    'max_events' => $resolved->maxEventsPerSpan(),
                    'max_event_attributes' => $resolved->maxEventAttributes(),
                )
            );
        } else {
            self::$tracer = new NoopTracer();
        }

        self::$logs = new LogsFacade(
            $resolved,
            self::$tracer,
            $logExporter,
            $redactor,
            self::$metrics,
            $resolved->maxLogRecordsPerRequest()
        );
        self::$handle = new ObservabilityHandle($resolved, $resource, $spanProcessor, self::$logs, self::$metrics);

        if (!self::$shutdownRegistered) {
            self::$shutdownRegistered = true;
            register_shutdown_function(array(__CLASS__, 'shutdown'));
        }

        return self::$handle;
    }

    public static function tracer()
    {
        if (!self::$tracer) {
            self::init();
        }
        return self::$tracer;
    }

    public static function metrics()
    {
        if (!self::$metrics) {
            self::init();
        }
        return self::$metrics;
    }

    public static function logs()
    {
        if (!self::$logs) {
            self::init();
        }
        return self::$logs;
    }

    /**
     * High-level request context (W3C baggage). Set business context once on the
     * inbound request and it auto-propagates on outbound hops (HTTP client, SOAP,
     * AMQP). Request-scoped: reset per request by the server/consumer entrypoints.
     */
    public static function context()
    {
        if (!self::$context) {
            self::$context = new \Elven\Observability\PhpLegacy\Context\ContextFacade();
        }
        return self::$context;
    }

    public static function isEnabled()
    {
        return self::$handle instanceof ObservabilityHandle && self::$handle->config()->isEnabled();
    }

    public static function config()
    {
        if (!self::$handle instanceof ObservabilityHandle) {
            self::init();
        }
        return self::$handle->config();
    }

    public static function shutdown()
    {
        try {
            ShutdownRegistry::run();
            if (self::$handle instanceof ObservabilityHandle) {
                $ok = self::$handle->shutdown();
                if (is_object(self::$tracer) && method_exists(self::$tracer, 'clearActiveSpans')) {
                    self::$tracer->clearActiveSpans();
                }
                if (is_object(self::$tracer) && method_exists(self::$tracer, 'setRootParentContext')) {
                    self::$tracer->setRootParentContext(
                        \Elven\Observability\PhpLegacy\Trace\SpanContext::invalid()
                    );
                }
                return $ok;
            }
        } catch (\Throwable $ignored) {
            return false;
        }
        return true;
    }

    public static function resetForTests()
    {
        self::$handle = null;
        self::$tracer = null;
        self::$metrics = null;
        self::$logs = null;
        self::$context = null;
        self::$debugFingerprint = null;
        ShutdownRegistry::resetForTests();
    }

    private static function refreshRootContext($resolved)
    {
        if (!method_exists(self::$tracer, 'setRootParentContext') || !method_exists(self::$tracer, 'currentSpan')) {
            return;
        }
        if (self::$tracer->currentSpan() !== null) {
            return;
        }
        $rootParent = $resolved->isEnabled() && $resolved->hasPropagator('tracecontext')
            ? (new TraceContextPropagator())->extract($_SERVER)
            : \Elven\Observability\PhpLegacy\Trace\SpanContext::invalid();
        self::$tracer->setRootParentContext($rootParent);
    }

    private static function debugDiagnostic($resolved)
    {
        try {
            if (
                $resolved->isDebug()
                && $resolved->disabledReason() !== ''
                && self::$debugFingerprint !== $resolved->fingerprint()
            ) {
                self::$debugFingerprint = $resolved->fingerprint();
                error_log('[elven-otel] ' . $resolved->disabledReason());
            }
        } catch (\Throwable $ignored) {
        }
    }
}
