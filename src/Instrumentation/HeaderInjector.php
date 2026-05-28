<?php

namespace Elven\Observability\PhpLegacy\Instrumentation;

use Elven\Observability\PhpLegacy\Observability;
use Elven\Observability\PhpLegacy\Propagation\BaggagePropagator;
use Elven\Observability\PhpLegacy\Propagation\TraceContextPropagator;
use Elven\Observability\PhpLegacy\Support\HeaderSanitizer;
use Elven\Observability\PhpLegacy\Trace\SpanContext;

final class HeaderInjector
{
    private function __construct()
    {
    }

    public static function inject(array $headers, array $baggage = array())
    {
        return self::injectContext($headers, Observability::tracer()->currentSpanContext(), $baggage);
    }

    public static function injectContext(array $headers, SpanContext $context, array $baggage = array())
    {
        $config = Observability::init()->config();
        if ($config->hasPropagator('tracecontext')) {
            (new TraceContextPropagator())->inject($headers, $context);
        }
        if ($baggage && $config->hasPropagator('baggage')) {
            (new BaggagePropagator())->inject($headers, $baggage);
        }
        return self::sanitizeMap($headers);
    }

    public static function toHeaderLines(array $headers)
    {
        return HeaderSanitizer::toHeaderLines($headers);
    }

    private static function sanitizeMap(array $headers)
    {
        $safe = array();
        foreach ($headers as $key => $value) {
            $name = HeaderSanitizer::sanitizeName($key);
            if ($name === '') {
                continue;
            }
            $safe[$name] = HeaderSanitizer::sanitizeValue($value);
        }
        return $safe;
    }
}
