<?php

namespace Elven\Observability\PhpLegacy\Instrumentation;

final class CurlInstrumentation
{
    public static function injectHeaderLines(array $headerLines)
    {
        $headers = \Elven\Observability\PhpLegacy\Support\HeaderSanitizer::headerLinesToMap($headerLines);
        return HeaderInjector::toHeaderLines(HeaderInjector::inject($headers));
    }

    public static function headersForCurl(array $headers = array(), $span = null)
    {
        if (is_object($span) && method_exists($span, 'context')) {
            return HeaderInjector::toHeaderLines(HeaderInjector::injectContext($headers, $span->context()));
        }
        return HeaderInjector::toHeaderLines(HeaderInjector::inject($headers));
    }

    public static function instrument($method, $url, callable $callback, array $attributes = array())
    {
        return HttpClientInstrumentation::instrument($method, $url, $callback, $attributes);
    }
}
