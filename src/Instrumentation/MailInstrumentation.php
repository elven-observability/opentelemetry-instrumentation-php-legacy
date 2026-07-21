<?php

namespace Elven\Observability\PhpLegacy\Instrumentation;

final class MailInstrumentation
{
    public static function trace($provider, callable $callback, array $attributes = array())
    {
        return DbInstrumentation::trace('Mail send ' . $provider, 'smtp', (string) $provider, array_merge(array(
            'messaging.system' => 'email',
            'messaging.operation.name' => 'send',
            'messaging.operation.type' => 'send',
            'messaging.operation' => 'send',
            'dependency_type' => 'smtp',
            'dependency_name' => (string) $provider,
        ), $attributes), $callback);
    }
}
