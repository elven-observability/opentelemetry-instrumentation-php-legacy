<?php

namespace Elven\Observability\PhpLegacy\Instrumentation;

use Elven\Observability\PhpLegacy\Observability;
use Elven\Observability\PhpLegacy\Privacy\DbStatementSanitizer;
use Elven\Observability\PhpLegacy\Trace\NoopSpan;
use Elven\Observability\PhpLegacy\Trace\Span;

final class DbInstrumentation
{
    public static function traceQuery($system, $operation, $database, callable $callback, $statement = null, array $attributes = array())
    {
        $summary = $statement ? DbStatementSanitizer::summary($statement) : strtoupper((string) $operation);
        $attrs = array_merge(array(
            'db.system' => (string) $system,
            'db.system.name' => (string) $system,
            'db.name' => (string) $database,
            'db.namespace' => (string) $database,
            'db.operation.name' => strtoupper((string) $operation),
            'db.query.summary' => $summary,
            'dependency_type' => 'db',
            'dependency_name' => (string) $system,
        ), $attributes);
        if ($statement !== null) {
            try {
                if (Observability::config()->captureDbStatement()) {
                    $attrs['db.statement'] = $statement;
                    $attrs['db.query.text'] = $statement;
                }
            } catch (\Throwable $e) {
                // Telemetry config lookup must never affect the database call.
            }
        }
        $spanName = $summary !== '' && $summary !== 'UNKNOWN'
            ? $summary
            : 'DB ' . strtoupper((string) $operation);
        return self::trace($spanName, 'db', (string) $system, $attrs, $callback);
    }

    public static function trace(
        $spanName,
        $dependencyType,
        $dependencyName,
        array $attributes,
        callable $callback,
        array $options = array()
    ) {
        $start = microtime(true);
        try {
            $tracer = Observability::tracer();
        } catch (\Throwable $ignored) {
            return call_user_func($callback, new NoopSpan());
        }
        $options['kind'] = isset($options['kind']) ? $options['kind'] : Span::KIND_CLIENT;
        $options['attributes'] = $attributes;
        return $tracer->withSpan($spanName, function ($span) use ($callback, $dependencyType, $dependencyName, $start) {
            try {
                return call_user_func($callback, $span);
            } finally {
                try {
                    Observability::metrics()->histogram('elven.php.dependency.duration')->record(
                        max(0.0, (microtime(true) - $start) * 1000),
                        array(
                            'dependency_type' => $dependencyType,
                            'dependency_name' => $dependencyName,
                        )
                    );
                } catch (\Throwable $ignored) {
                }
            }
        }, $options);
    }
}
