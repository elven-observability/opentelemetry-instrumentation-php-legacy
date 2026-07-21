<?php

namespace Elven\Observability\PhpLegacy\Instrumentation;

use Elven\Observability\PhpLegacy\Context\RequestContext;
use Elven\Observability\PhpLegacy\Observability;
use Elven\Observability\PhpLegacy\Trace\NoopSpan;
use Elven\Observability\PhpLegacy\Trace\Span;

final class CliInstrumentation
{
    private function __construct()
    {
    }

    public static function run($jobName, callable $callback, array $attributes = array(), $forceFlush = false)
    {
        $jobName = self::normalizeName($jobName);
        $start = microtime(true);
        /** @var Span|NoopSpan $span */
        $span = new NoopSpan();
        $failed = false;

        try {
            RequestContext::reset();
            $span = Observability::tracer()->startSpan('job ' . $jobName, array(
                'kind' => Span::KIND_INTERNAL,
                'attributes' => array_merge(array(
                    'job.name' => $jobName,
                    'process.runtime.name' => 'php',
                ), $attributes),
            ));
        } catch (\Throwable $ignored) {
        }

        try {
            return call_user_func($callback, $span);
        } catch (\Throwable $e) {
            $failed = true;
            try {
                $span->recordException($e);
            } catch (\Throwable $ignored) {
            }
            throw $e;
        } finally {
            try {
                $result = $failed ? 'error' : 'success';
                Observability::metrics()->histogram('elven.php.job.duration', 's')->record(
                    max(0.0, microtime(true) - $start),
                    array('operation' => $jobName, 'result' => $result)
                );
                if ($failed) {
                    Observability::metrics()->counter('elven.php.job.errors')->add(
                        1,
                        array('operation' => $jobName, 'error_type' => 'exception')
                    );
                }
            } catch (\Throwable $ignored) {
            }
            self::finishSpan($span);
            if ($forceFlush) {
                try {
                    Observability::init()->forceFlush();
                } catch (\Throwable $ignored) {
                }
            }
        }
    }

    private static function normalizeName($name)
    {
        $name = strtolower(trim((string) $name));
        $name = preg_replace('/[^a-z0-9_.-]/', '_', $name);
        return $name !== '' ? substr($name, 0, 80) : 'unknown';
    }

    /**
     * @param Span|NoopSpan $span
     */
    private static function finishSpan($span)
    {
        try {
            if (!$span->isEnded()) {
                $span->end();
            }
        } catch (\Throwable $ignored) {
        }
    }
}
