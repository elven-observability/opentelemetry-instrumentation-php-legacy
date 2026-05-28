<?php

namespace Elven\Observability\PhpLegacy\Instrumentation;

use Elven\Observability\PhpLegacy\Observability;
use Elven\Observability\PhpLegacy\Support\HeaderSanitizer;
use Elven\Observability\PhpLegacy\Trace\Span;

final class SoapInstrumentation
{
    public static function injectHttpHeaders(array $headers, $span = null)
    {
        $map = HeaderSanitizer::headerLinesToMap($headers);
        if (is_object($span) && method_exists($span, 'context')) {
            return HeaderInjector::toHeaderLines(HeaderInjector::injectContext($map, $span->context()));
        }
        return HeaderInjector::toHeaderLines(HeaderInjector::inject($map));
    }

    public static function soapHeaders(array $existing = array(), $span = null)
    {
        if (!class_exists('\\SoapHeader')) {
            return $existing;
        }
        $headers = is_object($span) && method_exists($span, 'context')
            ? HeaderInjector::injectContext(array(), $span->context())
            : HeaderInjector::inject(array());
        foreach ($headers as $key => $value) {
            $existing[] = new \SoapHeader('urn:elven-observability:trace-context', $key, $value, false);
        }
        return $existing;
    }

    public static function instrument($service, $method, $serverAddress, $timeout, callable $callback)
    {
        $start = microtime(true);
        return Observability::tracer()->withSpan('SOAP ' . $method, function ($span) use ($callback, $serverAddress, $start) {
            try {
                return call_user_func($callback, $span);
            } catch (\Throwable $e) {
                $span->recordException($e);
                throw $e;
            } finally {
                Observability::metrics()->histogram('elven.php.dependency.duration')->record((microtime(true) - $start) * 1000, array(
                    'dependency_type' => 'soap',
                    'dependency_name' => $serverAddress,
                ));
            }
        }, array(
            'kind' => Span::KIND_CLIENT,
            'attributes' => array(
                'rpc.system' => 'soap',
                'rpc.service' => (string) $service,
                'rpc.method' => (string) $method,
                'server.address' => (string) $serverAddress,
                'timeout' => (int) $timeout,
                'dependency_type' => 'soap',
                'dependency_name' => (string) $serverAddress,
            ),
        ));
    }
}
