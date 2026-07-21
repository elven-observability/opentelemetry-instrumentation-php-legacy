<?php

namespace Elven\Observability\PhpLegacy\Instrumentation;

use Elven\Observability\PhpLegacy\Observability;
use Elven\Observability\PhpLegacy\Trace\NoopSpan;
use Elven\Observability\PhpLegacy\Trace\Span;

final class AwsInstrumentation
{
    public static function trace($service, $operation, callable $callback, array $attributes = array())
    {
        return DbInstrumentation::trace('AWS ' . $service . ' ' . $operation, 'aws', (string) $service, array_merge(array(
            'rpc.system' => 'aws-api',
            'rpc.service' => (string) $service,
            'rpc.method' => (string) $operation,
            'dependency_type' => 'aws',
            'dependency_name' => (string) $service,
        ), $attributes), $callback);
    }

    /**
     * AWS SDK for PHP v3 middleware. Register on the sign step so the serialized
     * request exists and trace headers are present before SigV4 signs it.
     */
    public static function middleware($service)
    {
        $service = self::normalizeName($service, 'aws');
        return function (callable $handler) use ($service) {
            return function ($command, $request = null) use ($handler, $service) {
                if (!Observability::isEnabled()) {
                    return $handler($command, $request);
                }

                $operation = is_object($command) && method_exists($command, 'getName')
                    ? self::normalizeName($command->getName(), 'operation')
                    : 'operation';
                $host = self::requestHost($request);
                $start = microtime(true);
                $span = self::startMiddlewareSpan($service, $operation, $host);

                try {
                    $headers = HeaderInjector::injectContext(array(), $span->context());
                    foreach ($headers as $key => $value) {
                        if (is_object($request) && method_exists($request, 'withHeader')) {
                            $request = $request->withHeader($key, $value);
                        }
                    }
                } catch (\Throwable $ignored) {
                }

                try {
                    $promise = $handler($command, $request);
                } catch (\Throwable $e) {
                    self::recordFailure($span, $e);
                    self::finishMiddlewareSpan($span, $service, $start);
                    throw $e;
                }

                if (!is_object($promise) || !method_exists($promise, 'then')) {
                    self::finishMiddlewareSpan($span, $service, $start);
                    return $promise;
                }

                self::deactivate($span);
                try {
                    return $promise->then(
                        function ($result) use ($span, $service, $start) {
                            self::recordResultStatus($span, $result);
                            self::finishMiddlewareSpan($span, $service, $start);
                            return $result;
                        },
                        function ($reason) use ($span, $service, $start) {
                            self::recordFailure($span, $reason);
                            self::finishMiddlewareSpan($span, $service, $start);
                            return self::rejectedPromise($reason);
                        }
                    );
                } catch (\Throwable $e) {
                    self::recordFailure($span, $e);
                    self::finishMiddlewareSpan($span, $service, $start);
                    throw $e;
                }
            };
        };
    }

    /**
     * Idempotently attach instrumentation to an AWS SDK for PHP v3 client.
     * The client is returned unchanged when the SDK API is unavailable.
     */
    public static function register($client, $service)
    {
        try {
            if (!is_object($client) || !method_exists($client, 'getHandlerList')) {
                return $client;
            }
            $handlers = $client->getHandlerList();
            if (!is_object($handlers) || !method_exists($handlers, 'prependSign')) {
                return $client;
            }
            $name = 'elven.otel.' . self::normalizeName($service, 'aws');
            if (method_exists($handlers, 'remove')) {
                $handlers->remove($name);
            }
            $handlers->prependSign(self::middleware($service), $name);
        } catch (\Throwable $ignored) {
        }
        return $client;
    }

    private static function startMiddlewareSpan($service, $operation, $host)
    {
        try {
            return Observability::tracer()->startSpan('AWS ' . $service . ' ' . $operation, array(
                'kind' => Span::KIND_CLIENT,
                'attributes' => array(
                    'rpc.system' => 'aws-api',
                    'rpc.service' => $service,
                    'rpc.method' => $operation,
                    'server.address' => $host,
                    'dependency_type' => 'aws',
                    'dependency_name' => $service,
                ),
            ));
        } catch (\Throwable $ignored) {
            return new NoopSpan();
        }
    }

    private static function recordResultStatus($span, $result)
    {
        try {
            $metadata = null;
            if (is_array($result) && isset($result['@metadata'])) {
                $metadata = $result['@metadata'];
            } elseif ($result instanceof \ArrayAccess && isset($result['@metadata'])) {
                $metadata = $result['@metadata'];
            }
            if (is_array($metadata) && isset($metadata['statusCode'])) {
                $status = (int) $metadata['statusCode'];
                $span->setAttribute('http.response.status_code', $status);
                if ($status >= 400) {
                    $span->setStatus('ERROR', 'HTTP ' . $status);
                    $span->setAttribute('error.type', (string) $status);
                }
            }
        } catch (\Throwable $ignored) {
        }
    }

    private static function recordFailure($span, $reason)
    {
        try {
            if ($reason instanceof \Throwable) {
                $span->recordException($reason);
                $span->setAttribute('error.type', get_class($reason));
            } else {
                $span->setStatus('ERROR', 'aws_rejected');
                $span->setAttribute('error.type', 'aws_rejected');
            }
        } catch (\Throwable $ignored) {
        }
    }

    /**
     * @param Span|NoopSpan $span
     */
    private static function finishMiddlewareSpan($span, $service, $start)
    {
        try {
            Observability::metrics()->histogram('elven.php.dependency.duration')->record(
                max(0.0, (microtime(true) - $start) * 1000),
                array('dependency_type' => 'aws', 'dependency_name' => $service)
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
        throw new \RuntimeException('AWS request rejected');
    }

    private static function requestHost($request)
    {
        try {
            if (is_object($request) && method_exists($request, 'getUri')) {
                $parts = @parse_url((string) $request->getUri());
                return is_array($parts) && isset($parts['host']) ? strtolower((string) $parts['host']) : '';
            }
        } catch (\Throwable $ignored) {
        }
        return '';
    }

    private static function normalizeName($value, $fallback)
    {
        $value = strtolower(trim((string) $value));
        $value = preg_replace('/[^a-z0-9_.-]/', '_', $value);
        return $value !== '' ? substr($value, 0, 80) : $fallback;
    }
}
