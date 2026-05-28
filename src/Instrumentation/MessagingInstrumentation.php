<?php

namespace Elven\Observability\PhpLegacy\Instrumentation;

use Elven\Observability\PhpLegacy\Observability;
use Elven\Observability\PhpLegacy\Trace\Span;

final class MessagingInstrumentation
{
    public static function publish($system, $destination, callable $callback, array $attributes = array())
    {
        return self::trace('Message publish ' . $destination, Span::KIND_PRODUCER, $system, $destination, $callback, $attributes);
    }

    public static function consume($system, $destination, callable $callback, array $attributes = array())
    {
        return self::trace('Message consume ' . $destination, Span::KIND_CONSUMER, $system, $destination, $callback, $attributes);
    }

    public static function injectHeaders(array $headers = array())
    {
        return HeaderInjector::inject($headers);
    }

    private static function trace($name, $kind, $system, $destination, callable $callback, array $attributes)
    {
        $attributes = array_merge(array(
            'messaging.system' => (string) $system,
            'messaging.destination.name' => (string) $destination,
            'dependency_type' => 'messaging',
            'dependency_name' => (string) $system,
        ), $attributes);
        return Observability::tracer()->withSpan($name, $callback, array('kind' => $kind, 'attributes' => $attributes));
    }
}
