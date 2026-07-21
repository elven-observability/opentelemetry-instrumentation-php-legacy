<?php

namespace Elven\Observability\PhpLegacy\Resource;

use Elven\Observability\PhpLegacy\Config\ObservabilityConfig;
use Elven\Observability\PhpLegacy\Observability;
use Elven\Observability\PhpLegacy\Privacy\AttributeRedactor;
use Elven\Observability\PhpLegacy\Support\TelemetryValueLimiter;

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
            'telemetry.sdk.name' => Observability::SCOPE_NAME,
            'telemetry.sdk.language' => 'php',
            'telemetry.sdk.version' => self::version(),
            'process.runtime.name' => 'php',
            'process.runtime.version' => PHP_VERSION,
            // NOTE: process.pid is intentionally NOT emitted. Under PHP-FPM the PID
            // changes per worker (recycled via pm.max_requests), so when a collector
            // promotes resource attributes to metric labels
            // (prometheusremotewrite resource_to_telemetry_conversion), it produces
            // unbounded time-series cardinality. host.name stays: it is bounded per
            // host and useful to distinguish instance-pool replicas.
            'host.name' => php_uname('n'),
        );

        if ($config->serviceNamespace() !== '') {
            $attributes['service.namespace'] = $config->serviceNamespace();
        }
        if ($config->environment() !== '') {
            $attributes['deployment.environment.name'] = $config->environment();
            $attributes['deployment.environment'] = $config->environment();
        }

        return array_merge(self::safeCustomAttributes($config), $attributes);
    }

    public static function version()
    {
        return Observability::VERSION;
    }

    private static function safeCustomAttributes(ObservabilityConfig $config)
    {
        $safe = array();
        $redactor = new AttributeRedactor($config);
        foreach ($config->resourceAttributes() as $key => $value) {
            if (count($safe) >= 64) {
                break;
            }
            $key = substr(trim((string) $key), 0, 255);
            if ($key === '' || preg_match('/[\x00-\x1f\x7f]/', $key) === 1) {
                continue;
            }
            try {
                $value = TelemetryValueLimiter::limit($value, $config->maxAttributeLength());
                $safe[$key] = TelemetryValueLimiter::limit(
                    $redactor->redactValue($key, $value),
                    $config->maxAttributeLength()
                );
            } catch (\Throwable $ignored) {
            }
        }
        return $safe;
    }
}
