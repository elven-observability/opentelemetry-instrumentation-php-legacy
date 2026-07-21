<?php

namespace Elven\Observability\PhpLegacy\Tests\Unit;

use Elven\Observability\PhpLegacy\Config\EnvConfigResolver;
use Elven\Observability\PhpLegacy\Export\OtlpHttpJsonLogExporter;
use Elven\Observability\PhpLegacy\Export\OtlpHttpJsonMetricExporter;
use Elven\Observability\PhpLegacy\Export\OtlpHttpJsonTraceExporter;
use Elven\Observability\PhpLegacy\Logs\LogsFacade;
use Elven\Observability\PhpLegacy\Metrics\MetricFacade;
use Elven\Observability\PhpLegacy\Privacy\AttributeRedactor;
use Elven\Observability\PhpLegacy\Resource\ResourceBuilder;
use Elven\Observability\PhpLegacy\Support\Clock;
use Elven\Observability\PhpLegacy\Tests\Support\Env;
use Elven\Observability\PhpLegacy\Trace\Sampler\ParentBasedTraceIdRatioSampler;
use Elven\Observability\PhpLegacy\Trace\SpanContext;
use Elven\Observability\PhpLegacy\Trace\SpanProcessor;
use Elven\Observability\PhpLegacy\Trace\Tracer;
use PHPUnit\Framework\TestCase;

final class OtlpEncodingTest extends TestCase
{
    protected function setUp(): void
    {
        Env::reset();
    }

    public function testNanosecondClockIsAPlatformIndependentDecimalString(): void
    {
        self::assertMatchesRegularExpression('/^\d{19}$/', Clock::nowUnixNano());
    }

    public function testTracePayloadContainsResourceAndNestedSpans(): void
    {
        $config = EnvConfigResolver::resolve(array('service_name' => 'legacy-test', 'traces_exporter' => 'none'));
        $resource = ResourceBuilder::build($config);
        $redactor = new AttributeRedactor($config);
        $metrics = new MetricFacade(null, $redactor);
        $processor = new SpanProcessor(null, $metrics, 128);
        $tracer = new Tracer(new ParentBasedTraceIdRatioSampler(1.0), $processor, $redactor, SpanContext::invalid());

        $root = $tracer->startSpan('root');
        $child = $tracer->startSpan('child');
        $child->end();
        $root->end();

        $exporter = new OtlpHttpJsonTraceExporter($config, $resource);
        $payload = $exporter->payload(array($child, $root));

        self::assertSame('legacy-test', $payload['resourceSpans'][0]['resource']['attributes'][0]['value']['stringValue']);
        self::assertSame($root->context()->spanId(), $payload['resourceSpans'][0]['scopeSpans'][0]['spans'][0]['parentSpanId']);
    }

    public function testTracePayloadDoesNotFailOnObjectAttributesWhenRedactionIsDisabled(): void
    {
        $config = EnvConfigResolver::resolve(array(
            'service_name' => 'legacy-test',
            'redaction_enabled' => false,
        ));
        $resource = ResourceBuilder::build($config);
        $redactor = new AttributeRedactor($config);
        $metrics = new MetricFacade(null, $redactor);
        $processor = new SpanProcessor(null, $metrics, 128);
        $tracer = new Tracer(new ParentBasedTraceIdRatioSampler(1.0), $processor, $redactor, SpanContext::invalid());

        $span = $tracer->startSpan('object-attribute');
        $span->setAttribute('legacy.object', new \stdClass());
        $span->end();

        $payload = (new OtlpHttpJsonTraceExporter($config, $resource))->payload(array($span));
        $attributes = $payload['resourceSpans'][0]['scopeSpans'][0]['spans'][0]['attributes'];

        self::assertSame('[object stdClass]', $this->attributeValue($attributes, 'legacy.object'));
    }

    public function testMetricPayloadContainsCounterAndHistogram(): void
    {
        $config = EnvConfigResolver::resolve(array('service_name' => 'legacy-test'));
        $redactor = new AttributeRedactor($config);
        $facade = new MetricFacade(null, $redactor, array('service_name' => 'legacy-test', 'environment' => 'test'));
        $facade->counter('business.operation.started')->add(2, array('operation' => 'ticket_search', 'request_id' => 'must_drop'));
        $facade->histogram('http.server.request.duration')->record(15.5, array('route' => '/rest/v2/ticket/search'));

        $exporter = new OtlpHttpJsonMetricExporter($config, ResourceBuilder::build($config));
        $payload = $exporter->payload($facade->collect());

        $metrics = $payload['resourceMetrics'][0]['scopeMetrics'][0]['metrics'];
        self::assertSame('business.operation.started', $metrics[0]['name']);
        self::assertSame('http.server.request.duration', $metrics[1]['name']);
        self::assertSame('AGGREGATION_TEMPORALITY_CUMULATIVE', $metrics[0]['sum']['aggregationTemporality']);
        self::assertSame('AGGREGATION_TEMPORALITY_CUMULATIVE', $metrics[1]['histogram']['aggregationTemporality']);
        self::assertCount(3, $metrics[0]['sum']['dataPoints'][0]['attributes']);
        self::assertArrayHasKey('bucketCounts', $metrics[1]['histogram']['dataPoints'][0]);
        self::assertArrayHasKey('explicitBounds', $metrics[1]['histogram']['dataPoints'][0]);
    }

    public function testMetricPayloadCanUseDeltaTemporalityWhenExplicitlyConfigured(): void
    {
        $config = EnvConfigResolver::resolve(array(
            'service_name' => 'legacy-test',
            'metrics_temporality' => 'delta',
        ));
        $redactor = new AttributeRedactor($config);
        $facade = new MetricFacade(null, $redactor, array('service_name' => 'legacy-test'));
        $facade->counter('business.operation.started')->add(1, array('operation' => 'ticket_search'));
        $facade->histogram('http.server.request.duration')->record(15.5, array('route' => '/rest/v2/ticket/search'));

        $payload = (new OtlpHttpJsonMetricExporter($config, ResourceBuilder::build($config)))->payload($facade->collect());
        $metrics = $payload['resourceMetrics'][0]['scopeMetrics'][0]['metrics'];

        self::assertSame('AGGREGATION_TEMPORALITY_DELTA', $metrics[0]['sum']['aggregationTemporality']);
        self::assertSame('AGGREGATION_TEMPORALITY_DELTA', $metrics[1]['histogram']['aggregationTemporality']);
    }

    public function testMetricRequestAttributesApplyToAllPoints(): void
    {
        $config = EnvConfigResolver::resolve(array('service_name' => 'legacy-test'));
        $redactor = new AttributeRedactor($config);
        $facade = new MetricFacade(null, $redactor, array('service_name' => 'legacy-test', 'environment' => 'test'));
        $facade->setRequestAttributes(array(
            'traffic_source' => 'Sky Scanner',
            'traffic_channel' => 'METASEARCH',
            'partnerRedirectId' => 'must_drop',
        ));
        $facade->counter('business.operation.started')->add(1, array('operation' => 'ticket_search'));
        $facade->histogram('http.server.request.duration')->record(10, array('route' => '/rest/v2/aerial/search'));

        $payload = (new OtlpHttpJsonMetricExporter($config, ResourceBuilder::build($config)))->payload($facade->collect());
        $metrics = $payload['resourceMetrics'][0]['scopeMetrics'][0]['metrics'];

        self::assertSame(
            'skyscanner',
            $this->attributeValue($metrics[0]['sum']['dataPoints'][0]['attributes'], 'traffic_source')
        );
        self::assertSame(
            'metasearch',
            $this->attributeValue($metrics[1]['histogram']['dataPoints'][0]['attributes'], 'traffic_channel')
        );
        self::assertNull($this->attributeValue($metrics[0]['sum']['dataPoints'][0]['attributes'], 'partnerRedirectId'));
    }

    public function testMetricFacadeDropsExcessPointsAndReportsDropMetric(): void
    {
        $config = EnvConfigResolver::resolve(array('service_name' => 'legacy-test'));
        $redactor = new AttributeRedactor($config);
        $facade = new MetricFacade(null, $redactor, array('service_name' => 'legacy-test'), true, 1);

        $facade->counter('business.operation.started')->add(1, array('operation' => 'first'));
        $facade->counter('business.operation.started')->add(1, array('operation' => 'second'));

        $metrics = $facade->collect();
        $names = array_map(function ($metric) {
            return $metric['name'];
        }, $metrics);

        self::assertContains('business.operation.started', $names);
        self::assertContains('elven.php.exporter.dropped_metric_points', $names);
    }

    public function testMetricFacadeRejectsInvalidMonotonicAndNonFiniteValues(): void
    {
        $config = EnvConfigResolver::resolve(array('service_name' => 'legacy-test'));
        $facade = new MetricFacade(null, new AttributeRedactor($config));

        $facade->counter('invalid.counter')->add(-1);
        $facade->counter('invalid.counter')->add(NAN);
        $facade->histogram('invalid.histogram')->record(INF);
        $facade->gauge('invalid.gauge')->set(-INF);

        self::assertSame(array(), $facade->collect());
    }

    public function testLogPayloadContainsResourceCorrelationAndRedactedBody(): void
    {
        $config = EnvConfigResolver::resolve(array(
            'service_name' => 'legacy-test',
            'logs_exporter' => 'otlp',
        ));
        $resource = ResourceBuilder::build($config);
        $redactor = new AttributeRedactor($config);
        $metrics = new MetricFacade(null, $redactor);
        $processor = new SpanProcessor(null, $metrics, 128);
        $tracer = new Tracer(new ParentBasedTraceIdRatioSampler(1.0), $processor, $redactor, SpanContext::invalid());
        $logs = new LogsFacade($config, $tracer, new OtlpHttpJsonLogExporter($config, $resource), $redactor, $metrics);

        $tracer->withSpan('log-parent', function () use ($logs) {
            $logs->emit('info', 'Bearer abc.def.ghi user test@example.com', array(
                'operation' => 'ticket_search',
                'password' => 'secret',
            ));
        });

        $records = $this->drainLogRecords($logs);
        $payload = (new OtlpHttpJsonLogExporter($config, $resource))->payload($records);
        $record = $payload['resourceLogs'][0]['scopeLogs'][0]['logRecords'][0];

        self::assertSame('legacy-test', $payload['resourceLogs'][0]['resource']['attributes'][0]['value']['stringValue']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $record['traceId']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $record['spanId']);
        self::assertSame(1, $record['flags']);
        self::assertSame(9, $record['severityNumber']);
        self::assertStringNotContainsString('abc.def.ghi', $record['body']['stringValue']);
        self::assertStringNotContainsString('test@example.com', $record['body']['stringValue']);
        self::assertSame('[REDACTED]', $record['attributes'][1]['value']['stringValue']);
    }

    private function drainLogRecords(LogsFacade $logs): array
    {
        $reflection = new \ReflectionClass($logs);
        $property = $reflection->getProperty('records');
        if (PHP_VERSION_ID < 80100) {
            $property->setAccessible(true);
        }
        $records = $property->getValue($logs);
        $property->setValue($logs, array());
        return is_array($records) ? $records : array();
    }

    private function attributeValue(array $attributes, $key)
    {
        foreach ($attributes as $attribute) {
            if ($attribute['key'] === $key) {
                $value = $attribute['value'];
                if (isset($value['stringValue'])) {
                    return $value['stringValue'];
                }
                if (isset($value['intValue'])) {
                    return $value['intValue'];
                }
                if (isset($value['doubleValue'])) {
                    return $value['doubleValue'];
                }
                if (isset($value['boolValue'])) {
                    return $value['boolValue'];
                }
            }
        }
        return null;
    }
}
