<?php

namespace Elven\Observability\PhpLegacy\Instrumentation;

use Elven\Observability\PhpLegacy\Observability;
use Elven\Observability\PhpLegacy\Privacy\UrlSanitizer;
use Elven\Observability\PhpLegacy\Trace\NoopSpan;
use Elven\Observability\PhpLegacy\Trace\Span;

/**
 * PSR-7 middleware compatible with Guzzle 6 and 7.
 */
final class GuzzleInstrumentation
{
    public static function middleware()
    {
        return function (callable $handler) {
            return function ($request, array $options) use ($handler) {
                if (!Observability::isEnabled()) {
                    return $handler($request, $options);
                }

                $method = method_exists($request, 'getMethod')
                    ? strtoupper((string) $request->getMethod())
                    : 'GET';
                $url = method_exists($request, 'getUri') ? (string) $request->getUri() : '';
                $parts = @parse_url($url);
                $parts = is_array($parts) ? $parts : array();
                $host = isset($parts['host']) ? strtolower((string) $parts['host']) : 'http';
                $path = isset($parts['path']) ? (string) $parts['path'] : '/';
                $start = microtime(true);
                $span = self::startSpan($method, $host, $path, $parts);

                try {
                    $headers = HeaderInjector::injectContext(array(), $span->context());
                    foreach ($headers as $key => $value) {
                        if (method_exists($request, 'withHeader')) {
                            $request = $request->withHeader($key, $value);
                        }
                    }
                } catch (\Throwable $ignored) {
                }

                try {
                    $promise = $handler($request, $options);
                } catch (\Throwable $e) {
                    self::recordFailure($span, $e);
                    self::finish($span, $host, $start);
                    throw $e;
                }

                if (!is_object($promise) || !method_exists($promise, 'then')) {
                    self::finish($span, $host, $start);
                    return $promise;
                }

                self::deactivate($span);
                try {
                    return $promise->then(
                        function ($response) use ($span, $host, $start) {
                            try {
                                if (is_object($response) && method_exists($response, 'getStatusCode')) {
                                    $status = (int) $response->getStatusCode();
                                    $span->setAttribute('http.response.status_code', $status);
                                    if ($status >= 400) {
                                        $span->setStatus('ERROR', 'HTTP ' . $status);
                                        $span->setAttribute('error.type', (string) $status);
                                    }
                                }
                            } catch (\Throwable $ignored) {
                            }
                            self::finish($span, $host, $start);
                            return $response;
                        },
                        function ($reason) use ($span, $host, $start) {
                            self::recordFailure($span, $reason);
                            self::finish($span, $host, $start);
                            return self::rejectedPromise($reason);
                        }
                    );
                } catch (\Throwable $e) {
                    self::recordFailure($span, $e);
                    self::finish($span, $host, $start);
                    throw $e;
                }
            };
        };
    }

    private static function startSpan($method, $host, $path, array $parts)
    {
        try {
            return Observability::tracer()->startSpan('HTTP ' . $method . ' ' . $host, array(
                'kind' => Span::KIND_CLIENT,
                'attributes' => array(
                    'http.request.method' => $method,
                    'server.address' => $host,
                    'server.port' => isset($parts['port']) ? (int) $parts['port'] : 0,
                    'url.path' => UrlSanitizer::sanitizePath($path),
                    'dependency_type' => 'http',
                    'dependency_name' => $host,
                ),
            ));
        } catch (\Throwable $ignored) {
            return new NoopSpan();
        }
    }

    private static function recordFailure($span, $reason)
    {
        try {
            if ($reason instanceof \Throwable) {
                $span->recordException($reason);
                $span->setAttribute('error.type', get_class($reason));
                if (
                    stripos($reason->getMessage(), 'timed out') !== false
                    || stripos($reason->getMessage(), 'timeout') !== false
                ) {
                    $span->setAttribute('error.type', 'timeout');
                }
            } else {
                $span->setStatus('ERROR', 'guzzle_rejected');
                $span->setAttribute('error.type', 'guzzle_rejected');
            }
        } catch (\Throwable $ignored) {
        }
    }

    /**
     * @param Span|NoopSpan $span
     */
    private static function finish($span, $host, $start)
    {
        try {
            Observability::metrics()->histogram('elven.php.dependency.duration')->record(
                max(0.0, (microtime(true) - $start) * 1000),
                array('dependency_type' => 'http', 'dependency_name' => $host)
            );
        } catch (\Throwable $ignored) {
        }
        try {
            if (is_object($span) && method_exists($span, 'isEnded') && !$span->isEnded()) {
                $span->end();
            }
        } catch (\Throwable $ignored) {
        }
    }

    private static function deactivate($span)
    {
        try {
            $tracer = Observability::tracer();
            if (method_exists($tracer, 'deactivateSpan')) {
                $tracer->deactivateSpan($span);
            }
        } catch (\Throwable $ignored) {
        }
    }

    private static function rejectedPromise($reason)
    {
        if (class_exists('GuzzleHttp\\Promise\\RejectedPromise')) {
            return new \GuzzleHttp\Promise\RejectedPromise($reason);
        }
        if ($reason instanceof \Throwable) {
            throw $reason;
        }
        throw new \RuntimeException('Guzzle request rejected');
    }
}
