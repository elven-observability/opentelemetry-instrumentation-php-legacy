<?php

namespace Elven\Observability\PhpLegacy\Instrumentation;

final class AwsInstrumentation
{
    public static function trace($service, $operation, callable $callback, array $attributes = array())
    {
        return DbInstrumentation::trace('AWS ' . $service . ' ' . $operation, 'aws', (string) $service, array_merge(array(
            'rpc.system' => 'aws-api',
            'rpc.service' => (string) $service,
            'rpc.method' => (string) $operation,
            'dependency_type' => 'aws',
            'dependency_name' => (string) $service,
        ), $attributes), $callback);
    }
}
