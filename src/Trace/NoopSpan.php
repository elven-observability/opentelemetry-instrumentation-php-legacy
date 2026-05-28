<?php

namespace Elven\Observability\PhpLegacy\Trace;

final class NoopSpan
{
    public function setAttribute($key, $value)
    {
        return $this;
    }

    public function setAttributes(array $attributes)
    {
        return $this;
    }

    public function addEvent($name, array $attributes = array())
    {
        return $this;
    }

    public function recordException($throwable)
    {
        return $this;
    }

    public function setStatus($code, $message = '')
    {
        return $this;
    }

    public function end()
    {
    }

    public function isEnded()
    {
        return true;
    }

    public function isSampled()
    {
        return false;
    }

    public function context()
    {
        return SpanContext::invalid();
    }
}
