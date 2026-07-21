<?php

namespace Elven\Observability\PhpLegacy\Instrumentation;

use Elven\Observability\PhpLegacy\Observability;
use Elven\Observability\PhpLegacy\Privacy\IdentifierHasher;
use Elven\Observability\PhpLegacy\Support\ShutdownRegistry;
use Elven\Observability\PhpLegacy\Trace\NoopSpan;
use Elven\Observability\PhpLegacy\Trace\Span;

/**
 * Idempotent request scope for legacy applications that may call exit/die.
 */
final class ServerRequestScope
{
    /** @var Span|NoopSpan */
    private $span;
    private $route;
    private $method;
    private $start;
    private $statusResolver;
    private $shutdownId;
    private $finished;

    public function __construct($span, $route, $method, $start, $statusResolver = null)
    {
        $this->span = $span;
        $this->route = (string) $route;
        $this->method = (string) $method;
        $this->start = (float) $start;
        $this->statusResolver = is_callable($statusResolver) ? $statusResolver : null;
        $this->finished = false;
        $this->shutdownId = ShutdownRegistry::register(array($this, 'finishAtShutdown'));
    }

    public function span()
    {
        return $this->span;
    }

    public function setAttribute($key, $value)
    {
        try {
            if (is_object($this->span) && method_exists($this->span, 'setAttribute')) {
                $this->span->setAttribute($key, $value);
            }
        } catch (\Throwable $ignored) {
        }
        return $this;
    }

    public function recordException($throwable)
    {
        try {
            if (is_object($this->span) && method_exists($this->span, 'recordException')) {
                $this->span->recordException($throwable);
            }
        } catch (\Throwable $ignored) {
        }
        return $this;
    }

    public function setHashedAttribute($key, $value)
    {
        $hashed = IdentifierHasher::hash($value);
        if ($hashed !== '') {
            $this->setAttribute($key, $hashed);
        }
        return $this;
    }

    public function finish($throwable = null, $statusCode = null)
    {
        if ($this->finished) {
            return;
        }
        $this->finished = true;
        ShutdownRegistry::unregister($this->shutdownId);

        try {
            $status = $statusCode === null
                ? HttpServerInstrumentation::resolveStatus($this->statusResolver)
                : HttpServerInstrumentation::normalizeStatusCode($statusCode);
            if ($status === null) {
                $status = HttpServerInstrumentation::resolveStatus($this->statusResolver);
            }

            Observability::metrics()->histogram('http.server.request.duration', 's')->record(
                max(0.0, microtime(true) - $this->start),
                array(
                    'route' => $this->route,
                    'method' => $this->method,
                    'status_code' => (string) $status,
                )
            );
            HttpServerInstrumentation::finish($this->span, $status, $throwable);
        } catch (\Throwable $ignored) {
            try {
                HttpServerInstrumentation::finish($this->span, $statusCode, $throwable);
            } catch (\Throwable $ignoredAgain) {
            }
        }
    }

    public function finishAtShutdown()
    {
        if ($this->finished) {
            return;
        }

        $fatal = self::fatalError();
        if ($fatal !== null) {
            try {
                if (is_object($this->span) && method_exists($this->span, 'addEvent')) {
                    $this->span->addEvent('exception', array(
                        'exception.type' => 'php.fatal_error',
                        'exception.message' => isset($fatal['message']) ? $fatal['message'] : '',
                    ));
                    $this->span->setStatus('ERROR', 'php.fatal_error');
                }
            } catch (\Throwable $ignored) {
            }
        }

        $this->finish(null, $fatal !== null ? 500 : null);
    }

    public function isFinished()
    {
        return $this->finished;
    }

    private static function fatalError()
    {
        $error = error_get_last();
        if (!is_array($error)) {
            return null;
        }
        $fatalTypes = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR);
        if (defined('E_RECOVERABLE_ERROR')) {
            $fatalTypes[] = E_RECOVERABLE_ERROR;
        }
        return in_array((int) $error['type'], $fatalTypes, true) ? $error : null;
    }
}
