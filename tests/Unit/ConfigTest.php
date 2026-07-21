<?php

namespace Elven\Observability\PhpLegacy\Tests\Unit;

use Elven\Observability\PhpLegacy\Config\EnvConfigResolver;
use Elven\Observability\PhpLegacy\Resource\ResourceBuilder;
use Elven\Observability\PhpLegacy\Tests\Support\Env;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    protected function setUp(): void
    {
        Env::reset();
    }

    public function testExplicitConfigWinsOverEnvironment(): void
    {
        putenv('OTEL_SERVICE_NAME=env-service');
        putenv('OTEL_RESOURCE_ATTRIBUTES=team=payments,service.version=env');

        $config = EnvConfigResolver::resolve(array(
            'service_name' => 'explicit-service',
            'environment' => 'staging',
            'resource_attributes' => array('team' => 'checkout'),
        ));

        self::assertSame('explicit-service', $config->serviceName());
        self::assertSame('staging', $config->environment());
        self::assertSame('checkout', $config->resourceAttributes()['team']);
    }

    public function testEncodedOtlpHeadersAndResourceValuesAreDecoded(): void
    {
        putenv('OTEL_EXPORTER_OTLP_HEADERS=Authorization=Bearer%20test-token,X-Tenant=org%2Cregion');
        putenv('OTEL_RESOURCE_ATTRIBUTES=team=customer%20success,region=br%2Csp');

        $config = EnvConfigResolver::resolve();

        self::assertSame('Bearer test-token', $config->headers()['Authorization']);
        self::assertSame('org,region', $config->headers()['X-Tenant']);
        self::assertSame('customer success', $config->resourceAttributes()['team']);
        self::assertSame('br,sp', $config->resourceAttributes()['region']);
    }

    public function testUnsupportedBaseProtocolDisablesEachInheritedSignalSafely(): void
    {
        putenv('OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf');

        $config = EnvConfigResolver::resolve();

        self::assertTrue($config->isEnabled());
        self::assertSame('http/protobuf', $config->tracesProtocol());
        self::assertSame('http/protobuf', $config->metricsProtocol());
        self::assertSame('http/protobuf', $config->logsProtocol());
        self::assertStringContainsString('unsupported', $config->disabledReason());
    }

    public function testSignalSpecificProtocolOverridesBaseWithoutAffectingOtherSignals(): void
    {
        putenv('OTEL_EXPORTER_OTLP_PROTOCOL=http/json');
        putenv('OTEL_EXPORTER_OTLP_TRACES_PROTOCOL=http/protobuf');

        $config = EnvConfigResolver::resolve();

        self::assertSame('http/protobuf', $config->tracesProtocol());
        self::assertSame('http/json', $config->metricsProtocol());
        self::assertSame('http/json', $config->logsProtocol());
        self::assertStringContainsString('traces protocol', $config->disabledReason());
    }

    public function testSignalSpecificLogEndpointAndLogLimitAreResolved(): void
    {
        putenv('OTEL_EXPORTER_OTLP_ENDPOINT=http://collector.local:4318');
        putenv('OTEL_EXPORTER_OTLP_LOGS_ENDPOINT=http://collector.local:4318/custom/logs');
        putenv('ELVEN_OTEL_MAX_LOG_RECORDS_PER_REQUEST=3');

        $config = EnvConfigResolver::resolve();

        self::assertSame('http://collector.local:4318/v1/traces', $config->tracesEndpoint());
        self::assertSame('http://collector.local:4318/v1/metrics', $config->metricsEndpoint());
        self::assertSame('http://collector.local:4318/custom/logs', $config->logsEndpoint());
        self::assertSame(3, $config->maxLogRecordsPerRequest());
    }

    public function testResourceSafetyLimitsAreCapped(): void
    {
        putenv('ELVEN_OTEL_EXPORT_TIMEOUT_MS=999999');
        putenv('ELVEN_OTEL_MAX_SPANS_PER_REQUEST=999999');
        putenv('ELVEN_OTEL_MAX_METRIC_POINTS_PER_REQUEST=999999');
        putenv('ELVEN_OTEL_MAX_LOG_RECORDS_PER_REQUEST=999999');

        $config = EnvConfigResolver::resolve();

        self::assertSame(30000, $config->timeoutMillis());
        self::assertSame(2048, $config->maxSpansPerRequest());
        self::assertSame(4096, $config->maxMetricPointsPerRequest());
        self::assertSame(4096, $config->maxLogRecordsPerRequest());
    }

    public function testEmptySignalSpecificEndpointsFallBackToBaseEndpoint(): void
    {
        putenv('OTEL_EXPORTER_OTLP_ENDPOINT=http://collector.local:4318');
        putenv('OTEL_EXPORTER_OTLP_TRACES_ENDPOINT=');
        putenv('OTEL_EXPORTER_OTLP_METRICS_ENDPOINT=');
        putenv('OTEL_EXPORTER_OTLP_LOGS_ENDPOINT=');

        $config = EnvConfigResolver::resolve();

        self::assertSame('http://collector.local:4318/v1/traces', $config->tracesEndpoint());
        self::assertSame('http://collector.local:4318/v1/metrics', $config->metricsEndpoint());
        self::assertSame('http://collector.local:4318/v1/logs', $config->logsEndpoint());
    }

    public function testBaseEndpointWithWrongSignalPathIsRewrittenPerSignal(): void
    {
        putenv('OTEL_EXPORTER_OTLP_ENDPOINT=http://collector.local:4318/v1/traces');

        $config = EnvConfigResolver::resolve();

        self::assertSame('http://collector.local:4318/v1/traces', $config->tracesEndpoint());
        self::assertSame('http://collector.local:4318/v1/metrics', $config->metricsEndpoint());
        self::assertSame('http://collector.local:4318/v1/logs', $config->logsEndpoint());
    }

    public function testEnvironmentKillSwitchWinsOverExplicitEnable(): void
    {
        putenv('ELVEN_OTEL_ENABLED=false');

        $config = EnvConfigResolver::resolve(array('enabled' => true));

        self::assertFalse($config->isEnabled());
        self::assertStringContainsString('ELVEN_OTEL_ENABLED=false', $config->disabledReason());
    }

    public function testRedactionCanBeDisabledByEnvironmentOrExplicitConfig(): void
    {
        putenv('ELVEN_OTEL_REDACTION_ENABLED=off');

        $config = EnvConfigResolver::resolve();
        self::assertFalse($config->redactionEnabled());

        $config = EnvConfigResolver::resolve(array('redaction_enabled' => true));
        self::assertTrue($config->redactionEnabled());
    }

    public function testMetricsTemporalityDefaultsToCumulativeAndSupportsDeltaEnv(): void
    {
        $config = EnvConfigResolver::resolve();
        self::assertSame('cumulative', $config->metricsTemporality());

        putenv('OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE=delta');
        $config = EnvConfigResolver::resolve();
        self::assertSame('delta', $config->metricsTemporality());

        putenv('ELVEN_OTEL_METRICS_TEMPORALITY=cumulative');
        $config = EnvConfigResolver::resolve();
        self::assertSame('cumulative', $config->metricsTemporality());
    }

    public function testReservedResourceAttributesCannotOverrideExplicitIdentity(): void
    {
        putenv('OTEL_RESOURCE_ATTRIBUTES=service.name=wrong,deployment.environment.name=wrong,team=payments');

        $config = EnvConfigResolver::resolve(array(
            'service_name' => 'explicit-service',
            'environment' => 'staging',
        ));
        $resource = ResourceBuilder::build($config);

        self::assertSame('explicit-service', $resource['service.name']);
        self::assertSame('staging', $resource['deployment.environment.name']);
        self::assertSame('payments', $resource['team']);
    }
}
