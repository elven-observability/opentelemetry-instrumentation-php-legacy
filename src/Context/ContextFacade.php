<?php

namespace Elven\Observability\PhpLegacy\Context;

use Elven\Observability\PhpLegacy\Privacy\IdentifierHasher;

/**
 * Thin instance facade over RequestContext so callers can use the same ergonomic
 * Observability::context()->set(...) style as Observability::metrics()/tracer().
 *
 * PHP 7.3 compatible.
 */
final class ContextFacade
{
    /**
     * @param string $key
     * @param mixed  $value
     * @return $this
     */
    public function set($key, $value)
    {
        RequestContext::set($key, $value);
        return $this;
    }

    /**
     * @param array<string,mixed> $values
     * @return $this
     */
    public function merge(array $values)
    {
        RequestContext::merge($values);
        return $this;
    }

    /**
     * Hash a request identifier before putting it in propagating baggage.
     * Configure ELVEN_OTEL_ID_HASH_SALT for low-entropy identifiers.
     */
    public function setHashed($key, $value)
    {
        $hashed = IdentifierHasher::hash($value);
        if ($hashed !== '') {
            RequestContext::set($key, $hashed);
        }
        return $this;
    }

    /**
     * @param string $key
     * @return string|null
     */
    public function get($key)
    {
        return RequestContext::get($key);
    }

    /**
     * @return array<string,string>
     */
    public function all()
    {
        return RequestContext::all();
    }

    public function reset()
    {
        RequestContext::reset();
        return $this;
    }
}
