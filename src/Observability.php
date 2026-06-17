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
    const VERSION = '0.5.3';

    /** Instrumentation scope name reported on every exported signal. */
    const SCOPE_NAME = 'elven-observability-php-legacy';

    private static $handle;
    private static $tracer;
    private static $metrics;
    private static $logs;
    private static $shutdownRegistered = false;

    private function __construct()
    {
    }

    public static function init(array $config = array())
    {
        $resolved = EnvConfigResolver::resolve($config);
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
        $metricsEnabled = $resolved->isEnabled() && strtolower($resolved->metricsExporter()) !== 'none';
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

        if ($resolved->isEnabled() && strtolower($resolved->tracesExporter()) !== 'none') {
            $traceExporter = new OtlpHttpJsonTraceExporter($resolved, $resource);
        }
        if ($resolved->isEnabled() && strtolower($resolved->logsExporter()) !== 'none') {
            $logExporter = new OtlpHttpJsonLogExporter($resolved, $resource);
        }

        $spanProcessor = new SpanProcessor($traceExporter, self::$metrics, $resolved->maxSpansPerRequest());

        if ($resolved->isEnabled()) {
            $rootParent = $resolved->hasPropagator('tracecontext')
                ? (new TraceContextPropagator())->extract($_SERVER)
                : \Elven\Observability\PhpLegacy\Trace\SpanContext::invalid();
            $sampler = new ParentBasedTraceIdRatioSampler($resolved->samplerArg(), $resolved->sampler());
            self::$tracer = new Tracer($sampler, $spanProcessor, $redactor, $rootParent);
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

    public static function isEnabled()
    {
        return self::$handle instanceof ObservabilityHandle && self::$handle->config()->isEnabled();
    }

    public static function shutdown()
    {
        if (self::$handle instanceof ObservabilityHandle) {
            $ok = self::$handle->shutdown();
            if (method_exists(self::$tracer, 'clearActiveSpans')) {
                self::$tracer->clearActiveSpans();
            }
            if (method_exists(self::$tracer, 'setRootParentContext')) {
                self::$tracer->setRootParentContext(\Elven\Observability\PhpLegacy\Trace\SpanContext::invalid());
            }
            return $ok;
        }
        return true;
    }

    public static function resetForTests()
    {
        self::$handle = null;
        self::$tracer = null;
        self::$metrics = null;
        self::$logs = null;
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
}
