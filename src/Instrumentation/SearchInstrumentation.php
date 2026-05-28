<?php

namespace Elven\Observability\PhpLegacy\Instrumentation;

final class SearchInstrumentation
{
    public static function trace($system, $operation, $index, callable $callback)
    {
        return DbInstrumentation::trace(ucfirst((string) $system) . ' ' . strtoupper((string) $operation), 'search', (string) $system, array(
            'db.system' => (string) $system,
            'db.operation.name' => strtoupper((string) $operation),
            'db.collection.name' => (string) $index,
            'dependency_type' => 'search',
            'dependency_name' => (string) $system,
        ), $callback);
    }
}
