<?php

namespace Elven\Observability\PhpLegacy\Resource;

use Elven\Observability\PhpLegacy\Config\ObservabilityConfig;

final class ResourceBuilder
{
    private function __construct()
    {
    }

    public static function build(ObservabilityConfig $config)
    {
        $attributes = array(
            'service.name' => $config->serviceName(),
            'service.version' => $config->serviceVersion(),
            'telemetry.sdk.name' => 'elven-observability-php-legacy',
            'telemetry.sdk.language' => 'php',
            'telemetry.sdk.version' => self::version(),
            'process.runtime.name' => 'php',
            'process.runtime.version' => PHP_VERSION,
            'process.pid' => getmypid(),
            'host.name' => php_uname('n'),
        );

        if ($config->serviceNamespace() !== '') {
            $attributes['service.namespace'] = $config->serviceNamespace();
        }
        if ($config->environment() !== '') {
            $attributes['deployment.environment.name'] = $config->environment();
            $attributes['deployment.environment'] = $config->environment();
        }

        return array_merge($config->resourceAttributes(), $attributes);
    }

    public static function version()
    {
        return '0.1.0';
    }
}
