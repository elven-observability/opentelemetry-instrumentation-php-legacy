<?php

namespace Elven\Observability\PhpLegacy\Instrumentation;

use Elven\Observability\PhpLegacy\Attribution\TrafficSourceResolver;
use Elven\Observability\PhpLegacy\Observability;
use Elven\Observability\PhpLegacy\Privacy\UrlSanitizer;
use Elven\Observability\PhpLegacy\Propagation\TraceContextPropagator;
use Elven\Observability\PhpLegacy\Trace\Span;

final class HttpServerInstrumentation
{
    public static function startFromGlobals($route = null)
    {
        $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
        $path = isset($_SERVER['REQUEST_URI']) ? parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) : '/';
        $route = $route ?: UrlSanitizer::sanitizePath($path);
        $scheme = self::scheme();
        $handle = Observability::init();
        $parent = $handle->config()->hasPropagator('tracecontext')
            ? (new TraceContextPropagator())->extract($_SERVER)
            : \Elven\Observability\PhpLegacy\Trace\SpanContext::invalid();
        $status = array(
            'kind' => Span::KIND_SERVER,
            'parent_context' => $parent,
            'attributes' => array(
                'http.request.method' => $method,
                'http.route' => $route,
                'url.path' => UrlSanitizer::sanitizePath($path),
                'url.scheme' => $scheme,
                'server.address' => isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : '',
                'server.port' => isset($_SERVER['SERVER_PORT']) ? (int) $_SERVER['SERVER_PORT'] : 0,
                'deployment.environment.name' => $handle->config()->environment(),
                'service.name' => $handle->config()->serviceName(),
                'host.name' => php_uname('n'),
            ),
        );
        if (isset($_SERVER['HTTP_USER_AGENT'])) {
            $status['attributes']['user_agent.original'] = substr($_SERVER['HTTP_USER_AGENT'], 0, 256);
        }
        if (isset($_SERVER['REMOTE_ADDR']) && getenv('ELVEN_OTEL_CAPTURE_CLIENT_ADDRESS') === 'true') {
            $status['attributes']['client.address'] = $_SERVER['REMOTE_ADDR'];
        }
        $traffic = TrafficSourceResolver::attributesFromRequest(self::requestData(), $_SERVER);
        $status['attributes'] = array_merge($status['attributes'], $traffic);
        Observability::metrics()->setRequestAttributes($traffic);

        return Observability::tracer()->startSpan($method . ' ' . $route, $status);
    }

    public static function finish($span, $statusCode = null)
    {
        if (!is_object($span) || !method_exists($span, 'setAttribute')) {
            return;
        }
        if ($statusCode === null && function_exists('http_response_code')) {
            $statusCode = http_response_code();
        }
        $statusCode = $statusCode ?: 200;
        $span->setAttribute('http.response.status_code', (int) $statusCode);
        if ((int) $statusCode >= 500) {
            if (method_exists($span, 'setStatus')) {
                $span->setStatus('ERROR', 'HTTP ' . $statusCode);
            }
            Observability::metrics()->counter('elven.php.request.errors')->add(1, array(
                'status_code' => (string) $statusCode,
                'error_type' => 'http_5xx',
            ));
        }
        if (method_exists($span, 'end')) {
            $span->end();
        }
    }

    /**
     * Instrument an HTTP server request as a SERVER span.
     *
     * @param string        $route          Stable route template used as the span name suffix.
     * @param callable      $callback       Receives the span and runs the request handler.
     * @param callable|null $statusResolver Optional. Called when the span closes to obtain the
     *                                       real HTTP status code. Required for frameworks that
     *                                       build the response in an object and only flush the
     *                                       status line after the handler returns (e.g. Slim 2),
     *                                       where http_response_code() is still 200 at this point.
     *                                       Should return a valid HTTP status code (100-599);
     *                                       falsy/invalid results fall back to http_response_code().
     */
    public static function instrument($route, callable $callback, $statusResolver = null)
    {
        $span = self::startFromGlobals($route);
        $start = microtime(true);
        try {
            return call_user_func($callback, $span);
        } catch (\Throwable $e) {
            $span->recordException($e);
            throw $e;
        } finally {
            $status = self::resolveStatus($statusResolver);
            Observability::metrics()->histogram('http.server.request.duration', 's')->record(microtime(true) - $start, array(
                'route' => $route,
                'method' => isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET',
                'status_code' => (string) $status,
            ));
            self::finish($span, $status);
        }
    }

    /**
     * Resolve the response status, preferring an explicit resolver over http_response_code().
     *
     * @param callable|null $statusResolver
     * @return int
     */
    private static function resolveStatus($statusResolver)
    {
        if (is_callable($statusResolver)) {
            try {
                $resolved = call_user_func($statusResolver);
                $status = self::normalizeStatusCode($resolved);
                if ($status !== null) {
                    return $status;
                }
            } catch (\Throwable $e) {
                // Telemetry must never break the request: fall back below.
            }
        }
        $status = function_exists('http_response_code') ? http_response_code() : 200;
        $status = self::normalizeStatusCode($status);
        return $status !== null ? $status : 200;
    }

    /**
     * @param mixed $status
     * @return int|null
     */
    private static function normalizeStatusCode($status)
    {
        if (!is_numeric($status)) {
            return null;
        }
        $status = (int) $status;
        if ($status < 100 || $status > 599) {
            return null;
        }
        return $status;
    }

    private static function scheme()
    {
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return 'https';
        }
        if (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            return $_SERVER['HTTP_X_FORWARDED_PROTO'];
        }
        return 'http';
    }

    private static function requestData()
    {
        return array_merge($_GET, $_POST);
    }
}
