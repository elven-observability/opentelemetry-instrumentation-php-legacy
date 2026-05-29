<?php

namespace Elven\Observability\PhpLegacy\Tests\Unit;

use Elven\Observability\PhpLegacy\Config\EnvConfigResolver;
use Elven\Observability\PhpLegacy\Export\OtlpHttpJsonLogExporter;
use Elven\Observability\PhpLegacy\Export\OtlpHttpJsonMetricExporter;
use Elven\Observability\PhpLegacy\Export\OtlpHttpJsonTraceExporter;
use Elven\Observability\PhpLegacy\Instrumentation\HttpServerInstrumentation;
use Elven\Observability\PhpLegacy\Metrics\MetricFacade;
use Elven\Observability\PhpLegacy\Observability;
use Elven\Observability\PhpLegacy\Privacy\AttributeRedactor;
use Elven\Observability\PhpLegacy\Resource\ResourceBuilder;
use Elven\Observability\PhpLegacy\Tests\Support\Env;
use PHPUnit\Framework\TestCase;

final class VersionAndHttpStatusTest extends TestCase
{
    protected function setUp(): void
    {
        Env::reset();
        putenv('OTEL_TRACES_EXPORTER=none');
        putenv('OTEL_METRICS_EXPORTER=none');
        putenv('OTEL_LOGS_EXPORTER=none');
        Observability::init(array(
            'service_name' => 'legacy-version-test',
            'service_namespace' => 'booking',
            'environment' => 'staging',
        ));
        if (function_exists('http_response_code')) {
            http_response_code(200);
        }
    }

    public function testResourceReportsLibraryVersionFromSingleSource(): void
    {
        self::assertSame(Observability::VERSION, ResourceBuilder::version());

        $config = EnvConfigResolver::resolve(array('service_name' => 'legacy-version-test'));
        $resource = ResourceBuilder::build($config);
        self::assertSame(Observability::VERSION, $resource['telemetry.sdk.version']);
        self::assertSame(Observability::SCOPE_NAME, $resource['telemetry.sdk.name']);
    }

    public function testExporterScopesUseTheVersionConstant(): void
    {
        $config = EnvConfigResolver::resolve(array('service_name' => 'legacy-version-test'));
        $resource = ResourceBuilder::build($config);

        $traceScope = (new OtlpHttpJsonTraceExporter($config, $resource))
            ->payload(array())['resourceSpans'][0]['scopeSpans'][0]['scope'];
        self::assertSame(Observability::SCOPE_NAME, $traceScope['name']);
        self::assertSame(Observability::VERSION, $traceScope['version']);

        $facade = new MetricFacade(null, new AttributeRedactor($config));
        $facade->counter('probe')->add(1);
        $metricScope = (new OtlpHttpJsonMetricExporter($config, $resource))
            ->payload($facade->collect())['resourceMetrics'][0]['scopeMetrics'][0]['scope'];
        self::assertSame(Observability::SCOPE_NAME, $metricScope['name']);
        self::assertSame(Observability::VERSION, $metricScope['version']);

        $logScope = (new OtlpHttpJsonLogExporter($config, $resource))
            ->payload(array())['resourceLogs'][0]['scopeLogs'][0]['scope'];
        self::assertSame(Observability::SCOPE_NAME, $logScope['name']);
        self::assertSame(Observability::VERSION, $logScope['version']);
    }

    public function testStatusResolverOverridesHttpResponseCode(): void
    {
        $captured = null;
        HttpServerInstrumentation::instrument(
            'GET /rest/v2/customer/auth',
            function ($span) use (&$captured) {
                $captured = $span;
                return 'handled';
            },
            function () {
                return 401;
            }
        );

        $attributes = $captured->attributes();
        self::assertSame(401, $attributes['http.response.status_code']);
    }

    public function testFallsBackToHttpResponseCodeWhenNoResolver(): void
    {
        $captured = null;
        HttpServerInstrumentation::instrument(
            'GET /rest/v2/airport/search',
            function ($span) use (&$captured) {
                $captured = $span;
                return 'handled';
            }
        );

        $attributes = $captured->attributes();
        self::assertSame(200, $attributes['http.response.status_code']);
    }

    public function testResolverExceptionDoesNotBreakSpanAndFallsBack(): void
    {
        $captured = null;
        HttpServerInstrumentation::instrument(
            'GET /rest/v2/airport/search',
            function ($span) use (&$captured) {
                $captured = $span;
                return 'handled';
            },
            function () {
                throw new \RuntimeException('resolver boom');
            }
        );

        $attributes = $captured->attributes();
        self::assertSame(200, $attributes['http.response.status_code']);
    }

    public function testInvalidResolverStatusFallsBackToHttpResponseCode(): void
    {
        if (function_exists('http_response_code')) {
            http_response_code(418);
        }

        $captured = null;
        HttpServerInstrumentation::instrument(
            'GET /rest/v2/airport/search',
            function ($span) use (&$captured) {
                $captured = $span;
                return 'handled';
            },
            function () {
                return 999;
            }
        );

        $attributes = $captured->attributes();
        self::assertSame(418, $attributes['http.response.status_code']);
    }
}
