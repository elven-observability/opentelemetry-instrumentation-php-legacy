<?php

namespace Elven\Observability\PhpLegacy\Instrumentation;

final class RedisInstrumentation
{
    public static function trace($operation, callable $callback, array $attributes = array())
    {
        $attributes = array_merge(array(
            'db.system' => 'redis',
            'db.operation.name' => strtoupper((string) $operation),
            'dependency_type' => 'redis',
            'dependency_name' => 'redis',
        ), $attributes);
        return DbInstrumentation::trace('Redis ' . strtoupper((string) $operation), 'redis', 'redis', $attributes, $callback);
    }
}
