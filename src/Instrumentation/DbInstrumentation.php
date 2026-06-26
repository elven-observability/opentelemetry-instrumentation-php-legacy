<?php

namespace Elven\Observability\PhpLegacy\Instrumentation;

use Elven\Observability\PhpLegacy\Observability;
use Elven\Observability\PhpLegacy\Privacy\DbStatementSanitizer;
use Elven\Observability\PhpLegacy\Trace\Span;

final class DbInstrumentation
{
    public static function traceQuery($system, $operation, $database, callable $callback, $statement = null, array $attributes = array())
    {
        $attrs = array_merge(array(
            'db.system' => (string) $system,
            'db.name' => (string) $database,
            'db.operation.name' => strtoupper((string) $operation),
            'db.query.summary' => $statement ? DbStatementSanitizer::summary($statement) : strtoupper((string) $operation),
            'dependency_type' => 'db',
            'dependency_name' => (string) $system,
        ), $attributes);
        if ($statement !== null) {
            try {
                if (Observability::init()->config()->captureDbStatement()) {
                    $attrs['db.statement'] = $statement;
                }
            } catch (\Throwable $e) {
                // Telemetry config lookup must never affect the database call.
            }
        }
        return self::trace('DB ' . strtoupper((string) $operation), 'db', (string) $system, $attrs, $callback);
    }

    public static function trace($spanName, $dependencyType, $dependencyName, array $attributes, callable $callback)
    {
        $start = microtime(true);
        return Observability::tracer()->withSpan($spanName, function ($span) use ($callback, $dependencyType, $dependencyName, $start) {
            try {
                return call_user_func($callback, $span);
            } finally {
                Observability::metrics()->histogram('elven.php.dependency.duration')->record((microtime(true) - $start) * 1000, array(
                    'dependency_type' => $dependencyType,
                    'dependency_name' => $dependencyName,
                ));
            }
        }, array('kind' => Span::KIND_CLIENT, 'attributes' => $attributes));
    }
}
