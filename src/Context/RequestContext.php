<?php

namespace Elven\Observability\PhpLegacy\Context;

/**
 * Request-scoped store for high-level business context that should ride along the
 * whole trace as W3C baggage (e.g. traffic_source, is_bot, tenant, search_id).
 *
 * Set once where it is known (inbound request) and it is auto-injected on every
 * outbound hop (HTTP client, SOAP, AMQP) so downstream services/spans inherit the
 * context without re-deriving it.
 *
 * IMPORTANT (PHP-FPM): the worker process is reused across requests, so this
 * static store MUST be reset at the start of every request
 * (HttpServerInstrumentation::startFromGlobals / the consumer processor do this)
 * to avoid leaking one request's context into the next.
 *
 * Keys/values are kept small and bounded. Values are coerced to string and capped;
 * the BaggagePropagator additionally redacts and drops sensitive keys on the wire.
 *
 * PHP 7.3 compatible.
 */
final class RequestContext
{
    /**
     * Hard cap on stored members so a misbehaving caller cannot grow the store
     * unbounded within a request. Aligns with the BaggagePropagator member cap.
     */
    const MAX_ITEMS = 64;

    /** @var array<string,string> */
    private static $items = array();

    public static function reset()
    {
        self::$items = array();
    }

    /**
     * @param string $key
     * @param mixed  $value
     */
    public static function set($key, $value)
    {
        if (!is_string($key) || $key === '') {
            return;
        }
        if (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        }
        if (!is_scalar($value)) {
            return;
        }
        $value = (string) $value;
        if (strlen($value) > 512) {
            $value = substr($value, 0, 512);
        }
        // Bound the store: keep updating existing keys, but stop adding new ones
        // past the cap (defensive against unbounded growth on misuse).
        if (!isset(self::$items[$key]) && count(self::$items) >= self::MAX_ITEMS) {
            return;
        }
        self::$items[$key] = $value;
    }

    /**
     * @param array<string,mixed> $values
     */
    public static function merge(array $values)
    {
        foreach ($values as $key => $value) {
            self::set($key, $value);
        }
    }

    /**
     * @param string $key
     * @return string|null
     */
    public static function get($key)
    {
        return isset(self::$items[$key]) ? self::$items[$key] : null;
    }

    /**
     * @return array<string,string>
     */
    public static function all()
    {
        return self::$items;
    }
}
