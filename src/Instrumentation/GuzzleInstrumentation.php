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
    /**
     * @param array $config Optional, all keys may be omitted:
     *   - `dependency_name` (string): value of the span's `dependency_name` attribute, for
     *     consumers that keep their own closed dependency vocabulary. Without it the
     *     attribute is the host. The span name, `server.address` and the
     *     `elven.php.dependency.duration` label keep the host, exactly as
     *     HttpClientInstrumentation::instrument() does with its `$attributes`, so no
     *     existing metric series changes identity.
     *   - `mark_client_errors` (bool, default true): a 4xx response marks the CLIENT span
     *     ERROR with `error.type=<status>`, as HTTP semconv recommends for CLIENT spans.
     *     `false` leaves a 4xx span UNSET and without `error.type` (the status code is
     *     still recorded), for consumers whose contract treats a 4xx as a legitimate
     *     answer of the dependency (404 "not found" is not an incident). A 5xx, a
     *     transport failure and any rejection without a 4xx response are still ERROR.
     *     Values read from config or environment count as `false` when
     *     FILTER_VALIDATE_BOOLEAN reads them as false: `0`, `'0'`, `'false'`, `'no'`,
     *     `'off'` (any case, surrounding spaces ignored). `null`, a blank string and
     *     anything unrecognised keep the default.
     */
    public static function middleware(array $config = array())
    {
        $dependencyName = isset($config['dependency_name'])
            && is_string($config['dependency_name'])
            && trim($config['dependency_name']) !== ''
            ? trim($config['dependency_name'])
            : null;
        $markClientErrors = self::marksClientErrors($config);

        return function (callable $handler) use ($dependencyName, $markClientErrors) {
            return function ($request, array $options) use ($handler, $dependencyName, $markClientErrors) {
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
                $span = self::startSpan($method, $host, $path, $parts, $dependencyName);

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
                    self::recordFailure($span, $e, $markClientErrors);
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
                        function ($response) use ($span, $host, $start, $markClientErrors) {
                            try {
                                if (is_object($response) && method_exists($response, 'getStatusCode')) {
                                    $status = (int) $response->getStatusCode();
                                    $span->setAttribute('http.response.status_code', $status);
                                    if ($status >= 500 || ($status >= 400 && $markClientErrors)) {
                                        $span->setStatus('ERROR', 'HTTP ' . $status);
                                        $span->setAttribute('error.type', (string) $status);
                                    }
                                }
                            } catch (\Throwable $ignored) {
                            }
                            self::finish($span, $host, $start);
                            return $response;
                        },
                        function ($reason) use ($span, $host, $start, $markClientErrors) {
                            self::recordFailure($span, $reason, $markClientErrors);
                            self::finish($span, $host, $start);
                            return self::rejectedPromise($reason);
                        }
                    );
                } catch (\Throwable $e) {
                    self::recordFailure($span, $e, $markClientErrors);
                    self::finish($span, $host, $start);
                    throw $e;
                }
            };
        };
    }

    /**
     * `mark_client_errors` is off only for a value FILTER_VALIDATE_BOOLEAN reads as false.
     * FILTER_VALIDATE_BOOLEAN also reads `null` (a key filled with `?? null`) and a blank
     * string (an empty environment variable) as false; both keep the default here, so a
     * missing value does not silence 4xx errors. `getenv()` of an unset variable returns the
     * boolean `false`, which is indistinguishable from an explicit `false` and turns the
     * marking off.
     *
     * @return bool
     */
    private static function marksClientErrors(array $config)
    {
        if (!array_key_exists('mark_client_errors', $config)) {
            return true;
        }
        $value = $config['mark_client_errors'];
        if (!is_scalar($value) || (is_string($value) && trim($value) === '')) {
            return true;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== false;
    }

    private static function startSpan($method, $host, $path, array $parts, $dependencyName = null)
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
                    'dependency_name' => $dependencyName !== null ? $dependencyName : $host,
                ),
            ));
        } catch (\Throwable $ignored) {
            return new NoopSpan();
        }
    }

    private static function recordFailure($span, $reason, $markClientErrors = true)
    {
        try {
            if (!$markClientErrors) {
                // With `http_errors` on (or the middleware pushed outside Guzzle's
                // http_errors), a 4xx arrives as a rejection that still carries the
                // response. Same policy as the fulfilled branch: status recorded, span
                // left UNSET. The rejection itself is untouched by this method.
                $status = self::rejectionStatus($reason);
                if ($status >= 400 && $status < 500) {
                    $span->setAttribute('http.response.status_code', $status);
                    return;
                }
            }
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
     * HTTP status of the response carried by a rejection (Guzzle RequestException), or 0.
     *
     * @param mixed $reason
     * @return int
     */
    private static function rejectionStatus($reason)
    {
        try {
            if (is_object($reason) && method_exists($reason, 'getResponse')) {
                $response = $reason->getResponse();
                if (is_object($response) && method_exists($response, 'getStatusCode')) {
                    return (int) $response->getStatusCode();
                }
            }
        } catch (\Throwable $ignored) {
        }

        return 0;
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
