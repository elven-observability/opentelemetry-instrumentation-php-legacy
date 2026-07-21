<?php

namespace Elven\Observability\PhpLegacy\Instrumentation;

use Elven\Observability\PhpLegacy\Observability;
use Elven\Observability\PhpLegacy\Privacy\UrlSanitizer;
use Elven\Observability\PhpLegacy\Trace\Span;
use Elven\Observability\PhpLegacy\Trace\NoopSpan;

final class HttpClientInstrumentation
{
    public static function injectHeaders(array $headers = array())
    {
        return HeaderInjector::inject($headers);
    }

    public static function instrument($method, $url, callable $callback, array $attributes = array())
    {
        $parts = parse_url((string) $url);
        $host = isset($parts['host']) ? $parts['host'] : '';
        $path = isset($parts['path']) ? $parts['path'] : '';
        $method = strtoupper((string) $method);
        $dependencyName = $host !== '' ? strtolower((string) $host) : 'http';
        $spanName = 'HTTP ' . $method . ' ' . $dependencyName;
        $attrs = array_merge(array(
            'http.request.method' => $method,
            'server.address' => $host,
            'server.port' => isset($parts['port']) ? (int) $parts['port'] : 0,
            'url.path' => UrlSanitizer::sanitizePath($path ?: '/'),
            'dependency_type' => 'http',
            'dependency_name' => $dependencyName,
        ), $attributes);

        $start = microtime(true);
        try {
            $tracer = Observability::tracer();
        } catch (\Throwable $ignored) {
            return self::callClientCallback($callback, new NoopSpan(), array());
        }
        return $tracer->withSpan($spanName, function ($span) use ($callback, $dependencyName, $start) {
            try {
                $headers = HeaderInjector::injectContext(array(), $span->context());
                $result = self::callClientCallback($callback, $span, $headers);
                if (is_array($result) && isset($result['status_code'])) {
                    $span->setAttribute('http.response.status_code', (int) $result['status_code']);
                    if ((int) $result['status_code'] >= 400) {
                        $span->setStatus('ERROR', 'HTTP ' . $result['status_code']);
                        $span->setAttribute('error.type', (string) $result['status_code']);
                    }
                }
                return $result;
            } finally {
                try {
                    Observability::metrics()->histogram('elven.php.dependency.duration')->record(
                        max(0.0, (microtime(true) - $start) * 1000),
                        array(
                            'dependency_type' => 'http',
                            'dependency_name' => $dependencyName,
                        )
                    );
                } catch (\Throwable $ignored) {
                }
            }
        }, array('kind' => Span::KIND_CLIENT, 'attributes' => $attrs));
    }

    private static function callClientCallback(callable $callback, $span, array $headers)
    {
        if (self::callbackAcceptsHeaders($callback)) {
            return call_user_func($callback, $span, $headers);
        }
        return call_user_func($callback, $span);
    }

    private static function callbackAcceptsHeaders(callable $callback)
    {
        try {
            if (is_array($callback)) {
                $reflection = new \ReflectionMethod($callback[0], $callback[1]);
            } elseif (is_string($callback) && strpos($callback, '::') !== false) {
                $reflection = new \ReflectionMethod($callback);
            } elseif (is_object($callback) && !$callback instanceof \Closure) {
                $reflection = new \ReflectionMethod($callback, '__invoke');
            } else {
                $reflection = new \ReflectionFunction($callback);
            }
            return $reflection->getNumberOfParameters() >= 2;
        } catch (\ReflectionException $e) {
            return false;
        }
    }
}
