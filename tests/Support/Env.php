<?php

namespace Elven\Observability\PhpLegacy\Tests\Support;

use Elven\Observability\PhpLegacy\Observability;

final class Env
{
    private static $keys = array(
        'ELVEN_OTEL_ENABLED',
        'OTEL_SERVICE_NAME',
        'OTEL_SERVICE_NAMESPACE',
        'OTEL_SERVICE_VERSION',
        'ELVEN_ENVIRONMENT',
        'OTEL_DEPLOYMENT_ENVIRONMENT',
        'OTEL_RESOURCE_ATTRIBUTES',
        'OTEL_EXPORTER_OTLP_ENDPOINT',
        'OTEL_EXPORTER_OTLP_TRACES_ENDPOINT',
        'OTEL_EXPORTER_OTLP_METRICS_ENDPOINT',
        'OTEL_EXPORTER_OTLP_LOGS_ENDPOINT',
        'OTEL_EXPORTER_OTLP_PROTOCOL',
        'OTEL_EXPORTER_OTLP_HEADERS',
        'OTEL_EXPORTER_OTLP_TIMEOUT',
        'OTEL_PROPAGATORS',
        'OTEL_TRACES_EXPORTER',
        'OTEL_TRACES_SAMPLER',
        'OTEL_TRACES_SAMPLER_ARG',
        'OTEL_METRICS_EXPORTER',
        'OTEL_LOGS_EXPORTER',
        'ELVEN_OTEL_LOG_CORRELATION_ENABLED',
        'ELVEN_OTEL_CAPTURE_DB_STATEMENT',
        'ELVEN_OTEL_REDACT_DB_STATEMENT',
        'ELVEN_OTEL_ALLOW_RAW_ATTRIBUTES',
        'ELVEN_OTEL_MAX_SPANS_PER_REQUEST',
        'ELVEN_OTEL_MAX_METRIC_POINTS_PER_REQUEST',
        'ELVEN_OTEL_MAX_LOG_RECORDS_PER_REQUEST',
        'ELVEN_OTEL_EXPORT_TIMEOUT_MS',
        'ELVEN_OTEL_CAPTURE_CLIENT_ADDRESS',
        'ELVEN_OTEL_DEBUG',
    );

    private function __construct()
    {
    }

    public static function reset()
    {
        Observability::resetForTests();
        foreach (self::$keys as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
        $_SERVER = array();
    }
}
