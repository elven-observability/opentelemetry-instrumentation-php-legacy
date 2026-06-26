<?php

namespace Elven\Observability\PhpLegacy\Instrumentation;

use Elven\Observability\PhpLegacy\Observability;

/**
 * Records cache read effectiveness (hit / miss / error) and operation latency as
 * low-cardinality telemetry. This is the visibility that latency-only Redis
 * instrumentation cannot give: the hit ratio, and whether a "no value" was a
 * genuine miss or a driver failure (which must never be read as a miss).
 *
 * Emits:
 *  - counter   elven.php.cache.operations { cache_name, result }   result = hit|miss|error
 *  - histogram elven.php.cache.operation.duration (ms) { cache_name, result }
 *
 * Ultra-defensive: never throws on the request path. cache_name/result are a
 * small bounded enum so metric cardinality stays flat.
 *
 * PHP 7.3 compatible.
 */
final class CacheInstrumentation
{
    const RESULT_HIT = 'hit';
    const RESULT_MISS = 'miss';
    const RESULT_ERROR = 'error';

    /**
     * Record a single cache read outcome.
     *
     * Defensive boundary: inputs are normalized/validated, never trusted —
     * $durationMs is recorded only when numeric, $result/$cacheName are bounded.
     *
     * @param mixed $cacheName  Bounded logical cache name (e.g. 'redis', 'airports', 'session').
     * @param mixed $result     One of hit|miss|error (anything else -> miss).
     * @param mixed $durationMs Operation duration in ms; ignored unless numeric.
     */
    public static function record($cacheName, $result, $durationMs = null)
    {
        try {
            // Zero-cost when OTel is off: the cache read path is hot, so do not
            // touch the metric machinery unless instrumentation is enabled.
            if (!Observability::isEnabled()) {
                return;
            }
            $attributes = array(
                'cache_name' => self::normalizeName($cacheName),
                'result' => self::normalizeResult($result),
            );
            Observability::metrics()->counter('elven.php.cache.operations')->add(1, $attributes);
            if ($durationMs !== null && is_numeric($durationMs)) {
                Observability::metrics()
                    ->histogram('elven.php.cache.operation.duration', 'ms')
                    ->record((float) $durationMs, $attributes);
            }
        } catch (\Throwable $e) {
            // Telemetry must never break the cache path.
        }
    }

    /**
     * Wrap a cache read callable, classify its outcome and time it. The classifier
     * maps the returned value to hit|miss|error; the default classifier follows the
     * common convention value=hit, false=miss, null=driver-error.
     *
     * @param string        $cacheName
     * @param callable      $reader     Returns the cached value (or false/null).
     * @param callable|null $classifier Optional fn($value):string returning hit|miss|error.
     * @return mixed The reader's return value, untouched.
     */
    public static function observe($cacheName, callable $reader, $classifier = null)
    {
        $start = microtime(true);
        try {
            $value = call_user_func($reader);
        } catch (\Throwable $e) {
            // The cache driver itself threw: record it as an error outcome, then
            // rethrow so the caller's behavior is unchanged (never swallowed).
            try {
                self::record($cacheName, self::RESULT_ERROR, (microtime(true) - $start) * 1000.0);
            } catch (\Throwable $ignored) {
            }
            throw $e;
        }
        try {
            $result = is_callable($classifier)
                ? call_user_func($classifier, $value)
                : self::classifyDefault($value);
            self::record($cacheName, $result, (microtime(true) - $start) * 1000.0);
        } catch (\Throwable $e) {
            // Never let classification/recording affect the cache result.
        }
        return $value;
    }

    /**
     * @param mixed $value
     * @return string
     */
    public static function classifyDefault($value)
    {
        if ($value === null) {
            return self::RESULT_ERROR;
        }
        if ($value === false) {
            return self::RESULT_MISS;
        }
        return self::RESULT_HIT;
    }

    private static function normalizeResult($result)
    {
        $result = is_string($result) ? strtolower($result) : '';
        if ($result === self::RESULT_HIT || $result === self::RESULT_MISS || $result === self::RESULT_ERROR) {
            return $result;
        }
        return self::RESULT_MISS;
    }

    private static function normalizeName($cacheName)
    {
        $name = strtolower(trim((string) $cacheName));
        if ($name === '') {
            return 'unknown';
        }
        // Keep it a bounded, label-safe token. preg_replace returns null on a
        // (pathological) PCRE failure — fall back rather than emit an empty label.
        $sanitized = preg_replace('/[^a-z0-9_\-]/', '_', $name);
        if (!is_string($sanitized) || $sanitized === '') {
            return 'unknown';
        }
        return substr($sanitized, 0, 40);
    }
}
