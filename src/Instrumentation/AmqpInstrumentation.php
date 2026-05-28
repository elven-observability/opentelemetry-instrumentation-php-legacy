<?php

namespace Elven\Observability\PhpLegacy\Instrumentation;

final class AmqpInstrumentation
{
    public static function publish($destination, callable $callback, array $attributes = array())
    {
        return MessagingInstrumentation::publish('rabbitmq', $destination, $callback, $attributes);
    }

    public static function consume($destination, callable $callback, array $attributes = array())
    {
        return MessagingInstrumentation::consume('rabbitmq', $destination, $callback, $attributes);
    }

    public static function injectHeaders(array $headers = array())
    {
        return MessagingInstrumentation::injectHeaders($headers);
    }
}
