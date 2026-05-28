<?php

namespace Elven\Observability\PhpLegacy\Tests\Unit;

use Elven\Observability\PhpLegacy\Config\EnvConfigResolver;
use Elven\Observability\PhpLegacy\Export\OtlpHttpJsonMetricExporter;
use Elven\Observability\PhpLegacy\Export\OtlpHttpJsonTraceExporter;
use Elven\Observability\PhpLegacy\Metrics\MetricFacade;
use Elven\Observability\PhpLegacy\Privacy\AttributeRedactor;
use Elven\Observability\PhpLegacy\Resource\ResourceBuilder;
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
        self::assertCount(3, $metrics[0]['sum']['dataPoints'][0]['attributes']);
        self::assertArrayHasKey('bucketCounts', $metrics[1]['histogram']['dataPoints'][0]);
        self::assertArrayHasKey('explicitBounds', $metrics[1]['histogram']['dataPoints'][0]);
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
}
