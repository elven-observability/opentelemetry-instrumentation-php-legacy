<?php

namespace Elven\Observability\PhpLegacy\Config;

final class EnvConfigResolver
{
    private function __construct()
    {
    }

    public static function resolve(array $explicit = array())
    {
        $endpoint = self::string($explicit, 'endpoint', self::env('OTEL_EXPORTER_OTLP_ENDPOINT', 'http://localhost:4318'));
        $protocol = strtolower(self::string($explicit, 'protocol', self::env('OTEL_EXPORTER_OTLP_PROTOCOL', 'http/json')));
        $tracesProtocol = strtolower(self::string(
            $explicit,
            'traces_protocol',
            self::env('OTEL_EXPORTER_OTLP_TRACES_PROTOCOL', $protocol)
        ));
        $metricsProtocol = strtolower(self::string(
            $explicit,
            'metrics_protocol',
            self::env('OTEL_EXPORTER_OTLP_METRICS_PROTOCOL', $protocol)
        ));
        $logsProtocol = strtolower(self::string(
            $explicit,
            'logs_protocol',
            self::env('OTEL_EXPORTER_OTLP_LOGS_PROTOCOL', $protocol)
        ));
        $tracesEndpoint = self::string(
            $explicit,
            'traces_endpoint',
            self::env('OTEL_EXPORTER_OTLP_TRACES_ENDPOINT', self::signalEndpoint($endpoint, 'traces'))
        );
        $metricsEndpoint = self::string(
            $explicit,
            'metrics_endpoint',
            self::env('OTEL_EXPORTER_OTLP_METRICS_ENDPOINT', self::signalEndpoint($endpoint, 'metrics'))
        );
        $logsEndpoint = self::string(
            $explicit,
            'logs_endpoint',
            self::env('OTEL_EXPORTER_OTLP_LOGS_ENDPOINT', self::signalEndpoint($endpoint, 'logs'))
        );

        $enabledEnv = self::env('ELVEN_OTEL_ENABLED', null);
        $enabled = self::bool($explicit, 'enabled', $enabledEnv === null ? 'true' : $enabledEnv);
        $disabledReasons = array();
        if ($enabledEnv !== null && !self::bool(array(), 'enabled', $enabledEnv)) {
            $enabled = false;
            $disabledReasons[] = 'ELVEN_OTEL_ENABLED=false';
        }
        $signalProtocols = array(
            'traces' => $tracesProtocol,
            'metrics' => $metricsProtocol,
            'logs' => $logsProtocol,
        );
        foreach ($signalProtocols as $signal => $signalProtocol) {
            if ($signalProtocol !== 'http/json') {
                $disabledReasons[] = sprintf(
                    'OTLP %s protocol "%s" is unsupported; that signal is disabled. Use http/json.',
                    $signal,
                    $signalProtocol
                );
            }
        }

        $environment = self::string(
            $explicit,
            'environment',
            self::firstEnv(array('ELVEN_ENVIRONMENT', 'OTEL_DEPLOYMENT_ENVIRONMENT'), 'unknown')
        );

        $resourceAttributes = self::parseKeyValueList(self::env('OTEL_RESOURCE_ATTRIBUTES', ''));
        if (isset($explicit['resource_attributes']) && is_array($explicit['resource_attributes'])) {
            $resourceAttributes = array_merge($resourceAttributes, $explicit['resource_attributes']);
        }

        return new ObservabilityConfig(array(
            'enabled' => $enabled,
            'disabled_reason' => implode(' ', $disabledReasons),
            'debug' => self::bool($explicit, 'debug', self::env('ELVEN_OTEL_DEBUG', 'false')),
            'service_name' => self::string($explicit, 'service_name', self::env('OTEL_SERVICE_NAME', 'unknown-service')),
            'service_namespace' => self::string($explicit, 'service_namespace', self::env('OTEL_SERVICE_NAMESPACE', '')),
            'service_version' => self::string($explicit, 'service_version', self::env('OTEL_SERVICE_VERSION', '0.0.0')),
            'environment' => $environment,
            'resource_attributes' => $resourceAttributes,
            'endpoint' => $endpoint,
            'traces_endpoint' => $tracesEndpoint,
            'metrics_endpoint' => $metricsEndpoint,
            'logs_endpoint' => $logsEndpoint,
            'protocol' => $protocol,
            'traces_protocol' => $tracesProtocol,
            'metrics_protocol' => $metricsProtocol,
            'logs_protocol' => $logsProtocol,
            'headers' => array_merge(self::parseKeyValueList(self::env('OTEL_EXPORTER_OTLP_HEADERS', '')), self::arrayValue($explicit, 'headers')),
            'timeout_millis' => self::int(
                $explicit,
                'timeout_millis',
                self::env('ELVEN_OTEL_EXPORT_TIMEOUT_MS', self::env('OTEL_EXPORTER_OTLP_TIMEOUT', '200')),
                1,
                30000
            ),
            'propagators' => self::listValue(self::string($explicit, 'propagators', self::env('OTEL_PROPAGATORS', 'tracecontext,baggage'))),
            'traces_exporter' => self::string($explicit, 'traces_exporter', self::env('OTEL_TRACES_EXPORTER', 'otlp')),
            'metrics_exporter' => self::string($explicit, 'metrics_exporter', self::env('OTEL_METRICS_EXPORTER', 'otlp')),
            'metrics_temporality' => self::metricsTemporality(self::string(
                $explicit,
                'metrics_temporality',
                self::firstEnv(
                    array('ELVEN_OTEL_METRICS_TEMPORALITY', 'OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE'),
                    'cumulative'
                )
            )),
            'logs_exporter' => self::string($explicit, 'logs_exporter', self::env('OTEL_LOGS_EXPORTER', 'none')),
            'sampler' => self::string($explicit, 'sampler', self::env('OTEL_TRACES_SAMPLER', 'parentbased_traceidratio')),
            'sampler_arg' => self::float($explicit, 'sampler_arg', self::env('OTEL_TRACES_SAMPLER_ARG', '1'), 0.0, 1.0),
            'log_correlation_enabled' => self::bool($explicit, 'log_correlation_enabled', self::env('ELVEN_OTEL_LOG_CORRELATION_ENABLED', 'true')),
            'redaction_enabled' => self::bool($explicit, 'redaction_enabled', self::env('ELVEN_OTEL_REDACTION_ENABLED', 'true')),
            'capture_db_statement' => self::bool($explicit, 'capture_db_statement', self::env('ELVEN_OTEL_CAPTURE_DB_STATEMENT', 'false')),
            'redact_db_statement' => self::bool($explicit, 'redact_db_statement', self::env('ELVEN_OTEL_REDACT_DB_STATEMENT', 'true')),
            'allow_raw_attributes' => self::listValue(self::string($explicit, 'allow_raw_attributes', self::env('ELVEN_OTEL_ALLOW_RAW_ATTRIBUTES', ''))),
            'max_spans_per_request' => self::int(
                $explicit,
                'max_spans_per_request',
                self::env('ELVEN_OTEL_MAX_SPANS_PER_REQUEST', '128'),
                1,
                2048
            ),
            'max_metric_points_per_request' => self::int(
                $explicit,
                'max_metric_points_per_request',
                self::env('ELVEN_OTEL_MAX_METRIC_POINTS_PER_REQUEST', '512'),
                1,
                4096
            ),
            'max_log_records_per_request' => self::int(
                $explicit,
                'max_log_records_per_request',
                self::env('ELVEN_OTEL_MAX_LOG_RECORDS_PER_REQUEST', '512'),
                1,
                4096
            ),
            'max_attributes_per_span' => self::int(
                $explicit,
                'max_attributes_per_span',
                self::env('ELVEN_OTEL_MAX_ATTRIBUTES_PER_SPAN', '128'),
                1,
                256
            ),
            'max_attribute_length' => self::int(
                $explicit,
                'max_attribute_length',
                self::env('ELVEN_OTEL_MAX_ATTRIBUTE_LENGTH', '4096'),
                16,
                16384
            ),
            'max_events_per_span' => self::int(
                $explicit,
                'max_events_per_span',
                self::env('ELVEN_OTEL_MAX_EVENTS_PER_SPAN', '64'),
                0,
                256
            ),
            'max_event_attributes' => self::int(
                $explicit,
                'max_event_attributes',
                self::env('ELVEN_OTEL_MAX_EVENT_ATTRIBUTES', '32'),
                0,
                128
            ),
        ));
    }

    public static function parseKeyValueList($value)
    {
        $result = array();
        foreach (self::listValue($value) as $part) {
            $pieces = explode('=', $part, 2);
            if (count($pieces) === 2 && trim($pieces[0]) !== '') {
                $key = rawurldecode(trim($pieces[0]));
                if ($key !== '') {
                    $result[$key] = rawurldecode(trim($pieces[1]));
                }
            }
        }
        return $result;
    }

    private static function signalEndpoint($endpoint, $signal)
    {
        $endpoint = rtrim($endpoint, '/');
        if (preg_match('#/v1/(traces|metrics|logs)$#', $endpoint, $matches)) {
            return $matches[1] === $signal
                ? $endpoint
                : preg_replace('#/v1/(traces|metrics|logs)$#', '/v1/' . $signal, $endpoint);
        }
        return $endpoint . '/v1/' . $signal;
    }

    private static function env($name, $default = null)
    {
        $value = getenv($name);
        if ($value !== false && $value !== '') {
            return $value;
        }
        if (isset($_ENV[$name]) && $_ENV[$name] !== '') {
            return $_ENV[$name];
        }
        if (isset($_SERVER[$name]) && $_SERVER[$name] !== '') {
            return $_SERVER[$name];
        }
        return $default;
    }

    private static function firstEnv(array $names, $default)
    {
        foreach ($names as $name) {
            $value = self::env($name, null);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }
        return $default;
    }

    private static function metricsTemporality($value)
    {
        $value = strtolower(trim((string) $value));
        if ($value === 'delta') {
            return 'delta';
        }
        if ($value === 'lowmemory') {
            return 'delta';
        }
        return 'cumulative';
    }

    private static function string(array $explicit, $key, $default)
    {
        return isset($explicit[$key]) && $explicit[$key] !== '' ? (string) $explicit[$key] : (string) $default;
    }

    private static function bool(array $explicit, $key, $default)
    {
        $value = array_key_exists($key, $explicit) ? $explicit[$key] : $default;
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower(trim((string) $value)), array('1', 'true', 'yes', 'on'), true);
    }

    private static function int(array $explicit, $key, $default, $min, $max = null)
    {
        $value = array_key_exists($key, $explicit) ? $explicit[$key] : $default;
        $parsed = (int) $value;
        if ($parsed < $min) {
            return $min;
        }
        if ($max !== null && $parsed > $max) {
            return $max;
        }
        return $parsed;
    }

    private static function float(array $explicit, $key, $default, $min, $max)
    {
        $value = array_key_exists($key, $explicit) ? $explicit[$key] : $default;
        $parsed = (float) $value;
        if ($parsed < $min) {
            return $min;
        }
        if ($parsed > $max) {
            return $max;
        }
        return $parsed;
    }

    private static function arrayValue(array $explicit, $key)
    {
        return isset($explicit[$key]) && is_array($explicit[$key]) ? $explicit[$key] : array();
    }

    private static function listValue($value)
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map('trim', $value), array(__CLASS__, 'notEmpty')));
        }
        if ($value === null || $value === '') {
            return array();
        }
        return array_values(array_filter(array_map('trim', explode(',', (string) $value)), array(__CLASS__, 'notEmpty')));
    }

    private static function notEmpty($value)
    {
        return $value !== '';
    }
}
