<?php

namespace Elven\Observability\PhpLegacy\Tests\Integration;

use Elven\Observability\PhpLegacy\Instrumentation\CurlInstrumentation;
use Elven\Observability\PhpLegacy\Instrumentation\HttpServerInstrumentation;
use Elven\Observability\PhpLegacy\Observability;
use Elven\Observability\PhpLegacy\Tests\Support\Env;
use PHPUnit\Framework\TestCase;

final class FakeCollectorIntegrationTest extends TestCase
{
    private $process;
    private $port;
    private $eventsFile;

    protected function setUp(): void
    {
        Env::reset();
        $this->port = random_int(45000, 55000);
        $this->eventsFile = __DIR__ . '/../../var/fake-collector/events.jsonl';
        if (file_exists($this->eventsFile)) {
            unlink($this->eventsFile);
        }
        $this->startCollector();
    }

    protected function tearDown(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        Env::reset();
    }

    public function testFakeCollectorReceivesTraceAndMetricPayloads(): void
    {
        putenv('OTEL_EXPORTER_OTLP_ENDPOINT=http://127.0.0.1:' . $this->port);
        putenv('OTEL_EXPORTER_OTLP_PROTOCOL=http/json');
        putenv('OTEL_LOGS_EXPORTER=otlp');
        putenv('ELVEN_OTEL_EXPORT_TIMEOUT_MS=1000');

        $handle = Observability::init(array(
            'service_name' => 'legacy-booking-api',
            'service_namespace' => 'booking',
            'environment' => 'integration',
        ));

        Observability::tracer()->withSpan('integration.operation', function ($span) {
            $span->setAttribute('custom.safe_attribute', 'value');
            Observability::logs()->emit('INFO', 'integration log', array('operation' => 'ticket_search'));
            Observability::metrics()->counter('booking.ticket.search.started')->add(1, array(
                'operation' => 'ticket_search',
                'route' => '/rest/v14/ticket/search',
                'request_id' => 'not_allowed',
            ));
        });

        self::assertTrue($handle->forceFlush());

        $events = $this->readEvents();
        self::assertCount(3, $events);
        self::assertSame('/v1/traces', $events[0]['path']);
        self::assertSame('/v1/logs', $events[1]['path']);
        self::assertSame('/v1/metrics', $events[2]['path']);
        self::assertSame('legacy-booking-api', $events[0]['body']['resourceSpans'][0]['resource']['attributes'][0]['value']['stringValue']);
        self::assertSame('integration log', $events[1]['body']['resourceLogs'][0]['scopeLogs'][0]['logRecords'][0]['body']['stringValue']);
    }

    public function testServerSpanMarksErrorAndCurlInjectsTraceparent(): void
    {
        putenv('OTEL_TRACES_EXPORTER=none');
        putenv('OTEL_METRICS_EXPORTER=none');
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/rest/v14/ticket/search?token=secret';
        $_SERVER['SERVER_NAME'] = 'legacy.local';
        $_SERVER['SERVER_PORT'] = '8080';

        Observability::init(array('service_name' => 'legacy-test'));
        HttpServerInstrumentation::instrument('/rest/v14/ticket/search', function () {
            http_response_code(500);
            $headers = CurlInstrumentation::headersForCurl();
            self::assertMatchesRegularExpression('/^traceparent: 00-[a-f0-9]{32}-[a-f0-9]{16}-01$/', $headers[0]);
        });
    }

    public function testForceFlushReportsExporterFailure(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
            $this->process = null;
        }

        putenv('OTEL_EXPORTER_OTLP_ENDPOINT=http://127.0.0.1:9');
        putenv('OTEL_EXPORTER_OTLP_PROTOCOL=http/json');
        putenv('ELVEN_OTEL_EXPORT_TIMEOUT_MS=50');

        $handle = Observability::init(array('service_name' => 'flush-failure-test'));
        Observability::tracer()->withSpan('operation.before.failed.flush', function () {
            Observability::metrics()->counter('business.operation.started')->add(1, array('operation' => 'flush_failure'));
        });

        self::assertFalse($handle->forceFlush());
    }

    private function startCollector(): void
    {
        $command = sprintf(
            '%s -S 127.0.0.1:%d %s',
            escapeshellarg(PHP_BINARY),
            $this->port,
            escapeshellarg(__DIR__ . '/../../examples/fake-collector/server.php')
        );
        $this->process = proc_open($command, array(
            0 => array('pipe', 'r'),
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w'),
        ), $pipes, __DIR__ . '/../..');

        $deadline = microtime(true) + 5;
        do {
            $socket = @fsockopen('127.0.0.1', $this->port);
            if ($socket) {
                fclose($socket);
                return;
            }
            usleep(50000);
        } while (microtime(true) < $deadline);

        self::fail('fake collector did not start');
    }

    private function readEvents(): array
    {
        $deadline = microtime(true) + 3;
        do {
            if (file_exists($this->eventsFile)) {
                $lines = array_values(array_filter(explode("\n", trim(file_get_contents($this->eventsFile)))));
                if (count($lines) >= 3) {
                    return array_map(function ($line) {
                        return json_decode($line, true);
                    }, $lines);
                }
            }
            usleep(50000);
        } while (microtime(true) < $deadline);

        return array();
    }
}
