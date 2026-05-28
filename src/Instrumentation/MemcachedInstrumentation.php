<?php

namespace Elven\Observability\PhpLegacy\Instrumentation;

final class MemcachedInstrumentation
{
    public static function trace($operation, callable $callback, array $attributes = array())
    {
        $attributes = array_merge(array(
            'db.system' => 'memcached',
            'db.operation.name' => strtoupper((string) $operation),
            'dependency_type' => 'memcached',
            'dependency_name' => 'memcached',
        ), $attributes);
        return DbInstrumentation::trace('Memcached ' . strtoupper((string) $operation), 'memcached', 'memcached', $attributes, $callback);
    }
}
