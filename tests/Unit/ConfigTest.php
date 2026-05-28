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

    public function testUnsupportedProtocolDisablesTelemetrySafely(): void
    {
        putenv('OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf');

        $config = EnvConfigResolver::resolve();

        self::assertFalse($config->isEnabled());
        self::assertStringContainsString('not supported', $config->disabledReason());
    }

    public function testEnvironmentKillSwitchWinsOverExplicitEnable(): void
    {
        putenv('ELVEN_OTEL_ENABLED=false');

        $config = EnvConfigResolver::resolve(array('enabled' => true));

        self::assertFalse($config->isEnabled());
        self::assertStringContainsString('ELVEN_OTEL_ENABLED=false', $config->disabledReason());
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
