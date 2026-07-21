<?php

namespace Elven\Observability\PhpLegacy\Instrumentation;

use Elven\Observability\PhpLegacy\Trace\Span;

final class MessagingInstrumentation
{
    public static function publish($system, $destination, callable $callback, array $attributes = array())
    {
        return self::trace('publish ' . $destination, Span::KIND_PRODUCER, $system, $destination, 'send', $callback, $attributes);
    }

    public static function consume($system, $destination, callable $callback, array $attributes = array())
    {
        return self::trace('process ' . $destination, Span::KIND_CONSUMER, $system, $destination, 'process', $callback, $attributes);
    }

    public static function injectHeaders(array $headers = array())
    {
        return HeaderInjector::inject($headers);
    }

    private static function trace($name, $kind, $system, $destination, $operationType, callable $callback, array $attributes)
    {
        $attributes = array_merge(array(
            'messaging.system' => (string) $system,
            'messaging.destination.name' => (string) $destination,
            'messaging.operation.name' => $operationType,
            'messaging.operation.type' => $operationType,
            'messaging.operation' => $operationType,
            'dependency_type' => 'messaging',
            'dependency_name' => (string) $system,
        ), $attributes);
        return DbInstrumentation::trace(
            $name,
            'messaging',
            (string) $system,
            $attributes,
            $callback,
            array('kind' => $kind)
        );
    }
}
