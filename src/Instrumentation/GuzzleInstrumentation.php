<?php

namespace Elven\Observability\PhpLegacy\Instrumentation;

use Elven\Observability\PhpLegacy\Observability;
use Elven\Observability\PhpLegacy\Privacy\UrlSanitizer;
use Elven\Observability\PhpLegacy\Trace\Span;

final class GuzzleInstrumentation
{
    public static function middleware()
    {
        return function (callable $handler) {
            return function ($request, array $options) use ($handler) {
                $method = method_exists($request, 'getMethod') ? $request->getMethod() : 'GET';
                $url = method_exists($request, 'getUri') ? (string) $request->getUri() : '';
                $parts = parse_url($url);
                $host = isset($parts['host']) ? $parts['host'] : '';
                $path = isset($parts['path']) ? $parts['path'] : '/';
                $start = microtime(true);
                $span = Observability::tracer()->startSpan(strtoupper($method) . ' ' . $host . UrlSanitizer::sanitizePath($path), array(
                    'kind' => Span::KIND_CLIENT,
                    'attributes' => array(
                        'http.request.method' => strtoupper($method),
                        'server.address' => $host,
                        'url.path' => UrlSanitizer::sanitizePath($path),
                        'dependency_type' => 'http',
                        'dependency_name' => $host,
                    ),
                ));

                $headers = HeaderInjector::injectContext(array(), $span->context());
                foreach ($headers as $key => $value) {
                    if (method_exists($request, 'withHeader')) {
                        $request = $request->withHeader($key, $value);
                    }
                }

                try {
                    $promise = $handler($request, $options);
                } catch (\Throwable $e) {
                    $span->recordException($e);
                    $span->end();
                    throw $e;
                }

                if (is_object($promise) && method_exists($promise, 'then')) {
                    return $promise->then(
                        function ($response) use ($span, $host, $start) {
                            if (is_object($response) && method_exists($response, 'getStatusCode')) {
                                $span->setAttribute('http.response.status_code', $response->getStatusCode());
                                if ($response->getStatusCode() >= 500) {
                                    $span->setStatus('ERROR', 'HTTP ' . $response->getStatusCode());
                                }
                            }
                            self::recordDuration($host, $start);
                            $span->end();
                            return $response;
                        },
                        function ($reason) use ($span, $host, $start) {
                            if ($reason instanceof \Throwable) {
                                $span->recordException($reason);
                            } else {
                                $span->setStatus('ERROR', 'guzzle_rejected');
                            }
                            self::recordDuration($host, $start);
                            $span->end();
                            if ($reason instanceof \Throwable) {
                                throw $reason;
                            }
                            throw new \RuntimeException('Guzzle request rejected');
                        }
                    );
                }

                self::recordDuration($host, $start);
                $span->end();
                return $promise;
            };
        };
    }

    private static function recordDuration($host, $start)
    {
        Observability::metrics()->histogram('elven.php.dependency.duration')->record((microtime(true) - $start) * 1000, array(
            'dependency_type' => 'http',
            'dependency_name' => $host,
        ));
    }
}
