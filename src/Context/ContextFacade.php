<?php

namespace Elven\Observability\PhpLegacy\Context;

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
