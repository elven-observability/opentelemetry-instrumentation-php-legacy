<?php

namespace Elven\Observability\PhpLegacy\Tests\Unit;

use Elven\Observability\PhpLegacy\Instrumentation\CurlInstrumentation;
use Elven\Observability\PhpLegacy\Instrumentation\DbInstrumentation;
use Elven\Observability\PhpLegacy\Instrumentation\HeaderInjector;
use Elven\Observability\PhpLegacy\Instrumentation\HttpClientInstrumentation;
use Elven\Observability\PhpLegacy\Instrumentation\Slim2Instrumentation;
use Elven\Observability\PhpLegacy\Logs\MonologOtlpHandler;
use Elven\Observability\PhpLegacy\Logs\MonologTraceProcessor;
use Elven\Observability\PhpLegacy\Observability;
use Elven\Observability\PhpLegacy\Tests\Support\Env;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

final class LogsAndInstrumentationTest extends TestCase
{
    protected function setUp(): void
    {
        Env::reset();
        putenv('OTEL_TRACES_EXPORTER=none');
        putenv('OTEL_METRICS_EXPORTER=none');
        Observability::init(array(
            'service_name' => 'legacy-booking-api',
            'service_namespace' => 'booking',
            'environment' => 'staging',
        ));
    }

    public function testLogCorrelationContainsTraceFieldsInsideSpan(): void
    {
        Observability::tracer()->withSpan('operation', function () {
            $context = Observability::logs()->correlate(array('message_id' => 'abc'));
            self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $context['trace_id']);
            self::assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $context['span_id']);
            self::assertSame('01', $context['trace_flags']);
            self::assertSame('legacy-booking-api', $context['service_name']);
            self::assertSame('staging', $context['environment']);
        });
    }

    public function testMonologProcessorWritesExtraFields(): void
    {
        Observability::tracer()->withSpan('log-operation', function () {
            $record = (new MonologTraceProcessor())(array('extra' => array(), 'context' => array()));
            self::assertArrayHasKey('trace_id', $record['extra']);
            self::assertArrayHasKey('span_id', $record['extra']);
        });
    }

    public function testMonologOtlpHandlerBuffersSanitizedRecord(): void
    {
        Env::reset();
        putenv('OTEL_TRACES_EXPORTER=none');
        putenv('OTEL_METRICS_EXPORTER=none');
        putenv('OTEL_LOGS_EXPORTER=otlp');
        Observability::init(array('service_name' => 'log-export-test'));

        $logger = new Logger('legacy');
        $logger->pushHandler(new MonologOtlpHandler());
        $logger->warning('login failed for test@example.com', array('password' => 'secret'));

        $records = $this->drainLogRecords(Observability::logs());

        self::assertCount(1, $records);
        self::assertSame('WARNING', $records[0]['severityText']);
        self::assertStringNotContainsString('test@example.com', $records[0]['body']);
        self::assertSame('[REDACTED]', $records[0]['attributes']['log.context.password']);
    }

    public function testLogExportCanKeepRawValuesWhenRedactionIsDisabled(): void
    {
        Env::reset();
        putenv('OTEL_TRACES_EXPORTER=none');
        putenv('OTEL_METRICS_EXPORTER=none');
        putenv('OTEL_LOGS_EXPORTER=otlp');
        putenv('ELVEN_OTEL_REDACTION_ENABLED=false');
        Observability::init(array('service_name' => 'log-export-raw-test'));

        $logger = new Logger('legacy');
        $logger->pushHandler(new MonologOtlpHandler());
        $logger->warning('login failed for test@example.com', array('password' => 'secret'));

        $records = $this->drainLogRecords(Observability::logs());

        self::assertCount(1, $records);
        self::assertStringContainsString('test@example.com', $records[0]['body']);
        self::assertSame('secret', $records[0]['attributes']['log.context.password']);
    }

    public function testLogRecordLimitDropsExcessRecords(): void
    {
        Env::reset();
        putenv('OTEL_TRACES_EXPORTER=none');
        putenv('OTEL_METRICS_EXPORTER=none');
        putenv('OTEL_LOGS_EXPORTER=otlp');
        putenv('ELVEN_OTEL_MAX_LOG_RECORDS_PER_REQUEST=1');
        Observability::init(array('service_name' => 'log-limit-test'));

        self::assertTrue(Observability::logs()->emit('INFO', 'first'));
        self::assertFalse(Observability::logs()->emit('INFO', 'second'));

        self::assertCount(1, $this->drainLogRecords(Observability::logs()));
    }

    public function testCurlHeaderInjectionAddsTraceparent(): void
    {
        Observability::tracer()->withSpan('client-parent', function () {
            $headers = CurlInstrumentation::headersForCurl(array('Content-Type' => 'application/json'));
            self::assertContains('Content-Type: application/json', $headers);
            self::assertNotEmpty(preg_grep('/^traceparent: 00-[a-f0-9]{32}-[a-f0-9]{16}-01$/', $headers));
        });
    }

    public function testClientInstrumentationInjectsItsOwnSpanContext(): void
    {
        HttpClientInstrumentation::instrument('GET', 'https://api.example.test/orders/123456', function ($span, array $headers) {
            self::assertArrayHasKey('traceparent', $headers);
            self::assertStringContainsString('-' . $span->context()->spanId() . '-', $headers['traceparent']);
        });
    }

    public function testHeaderInjectionOutsideActiveSpanDoesNotLeakRootParent(): void
    {
        $_SERVER['HTTP_TRACEPARENT'] = '00-11111111111111111111111111111111-2222222222222222-01';
        Observability::init(array(
            'service_name' => 'legacy-booking-api',
            'service_namespace' => 'booking',
            'environment' => 'staging',
        ));

        self::assertArrayNotHasKey('traceparent', HeaderInjector::inject(array()));
    }

    public function testPropagatorsEnvCanDisableTraceContextInjection(): void
    {
        Env::reset();
        putenv('OTEL_TRACES_EXPORTER=none');
        putenv('OTEL_METRICS_EXPORTER=none');
        putenv('OTEL_PROPAGATORS=baggage');
        Observability::init(array('service_name' => 'no-tracecontext'));

        Observability::tracer()->withSpan('operation', function () {
            self::assertArrayNotHasKey('traceparent', HeaderInjector::inject(array()));
        });
    }

    public function testInitRefreshesRootContextForSameConfigBetweenRequests(): void
    {
        Env::reset();
        putenv('OTEL_TRACES_EXPORTER=none');
        putenv('OTEL_METRICS_EXPORTER=none');
        $config = array('service_name' => 'legacy-booking-api');

        $_SERVER['HTTP_TRACEPARENT'] = '00-11111111111111111111111111111111-2222222222222222-01';
        Observability::init($config);
        $_SERVER['HTTP_TRACEPARENT'] = '00-33333333333333333333333333333333-4444444444444444-01';
        Observability::init($config);

        $span = Observability::tracer()->startSpan('request-root-child');
        self::assertSame('33333333333333333333333333333333', $span->parentContext()->traceId());
        $span->end();
    }

    public function testKillSwitchDisablesLogCorrelationMutation(): void
    {
        Env::reset();
        putenv('ELVEN_OTEL_ENABLED=false');
        Observability::init(array('service_name' => 'disabled-service'));

        $context = array('existing' => 'value');
        self::assertSame($context, Observability::logs()->correlate($context));
    }

    public function testShutdownIsIdempotentWhenExporterFails(): void
    {
        Env::reset();
        Observability::resetForTests();
        putenv('OTEL_EXPORTER_OTLP_ENDPOINT=http://127.0.0.1:9');
        putenv('ELVEN_OTEL_EXPORT_TIMEOUT_MS=1');
        putenv('OTEL_TRACES_EXPORTER=otlp');
        putenv('OTEL_METRICS_EXPORTER=none');
        putenv('OTEL_LOGS_EXPORTER=none');

        $handle = Observability::init(array('service_name' => 'shutdown-idempotency-test'));
        Observability::tracer()->withSpan('export-will-fail', function () {
        });

        self::assertFalse($handle->shutdown());
        self::assertTrue($handle->shutdown());
    }

    public function testSlim2StableRoute(): void
    {
        self::assertSame('/rest/v14/ticket/search', Slim2Instrumentation::restRoute('14', 'Ticket', 'Search'));
    }

    public function testDbInstrumentationRedactsStatementAndReturnsCallbackResult(): void
    {
        $result = DbInstrumentation::traceQuery('mysql', 'select', 'booking', function ($span) {
            $span->setAttribute('db.statement', "select * from users where email='secret@example.com'");
            return 'ok';
        }, "select * from users where email='secret@example.com'");

        self::assertSame('ok', $result);
    }

    public function testDbInstrumentationDoesNotAttachStatementWhenCaptureIsOff(): void
    {
        Env::reset();
        putenv('OTEL_TRACES_EXPORTER=none');
        putenv('OTEL_METRICS_EXPORTER=none');
        putenv('ELVEN_OTEL_REDACTION_ENABLED=false');
        putenv('ELVEN_OTEL_CAPTURE_DB_STATEMENT=false');
        Observability::init(array('service_name' => 'db-capture-off-test'));

        DbInstrumentation::traceQuery('mysql', 'select', 'booking', function ($span) {
            self::assertArrayNotHasKey('db.statement', $span->attributes());
            self::assertSame('SELECT users', $span->attributes()['db.query.summary']);
            return 'ok';
        }, "select * from users where email='secret@example.com'");
    }

    public function testDbInstrumentationCanAttachRawStatementWhenExplicitlyEnabled(): void
    {
        Env::reset();
        putenv('OTEL_TRACES_EXPORTER=none');
        putenv('OTEL_METRICS_EXPORTER=none');
        putenv('ELVEN_OTEL_REDACTION_ENABLED=false');
        putenv('ELVEN_OTEL_CAPTURE_DB_STATEMENT=true');
        putenv('ELVEN_OTEL_REDACT_DB_STATEMENT=false');
        Observability::init(array('service_name' => 'db-capture-raw-test'));

        DbInstrumentation::traceQuery('mysql', 'select', 'booking', function ($span) {
            self::assertSame(
                "select * from users where email='secret@example.com'",
                $span->attributes()['db.statement']
            );
            return 'ok';
        }, "select * from users where email='secret@example.com'");
    }

    public function testMetricPointLimitEnvIsAppliedByObservability(): void
    {
        Env::reset();
        putenv('OTEL_TRACES_EXPORTER=none');
        putenv('ELVEN_OTEL_MAX_METRIC_POINTS_PER_REQUEST=1');
        Observability::init(array('service_name' => 'metric-limit-test'));

        Observability::metrics()->counter('business.operation.started')->add(1, array('operation' => 'first'));
        Observability::metrics()->counter('business.operation.started')->add(1, array('operation' => 'second'));

        $metrics = Observability::metrics()->collect();
        $names = array_map(function ($metric) {
            return $metric['name'];
        }, $metrics);

        self::assertContains('business.operation.started', $names);
        self::assertContains('elven.php.exporter.dropped_metric_points', $names);
    }

    private function drainLogRecords($logs): array
    {
        $reflection = new \ReflectionClass($logs);
        $property = $reflection->getProperty('records');
        $property->setAccessible(true);
        $records = $property->getValue($logs);
        $property->setValue($logs, array());
        return is_array($records) ? $records : array();
    }
}
