<?php

namespace Elven\Observability\PhpLegacy\Trace;

final class NoopTracer
{
    public function startSpan($name, array $options = array())
    {
        return new NoopSpan();
    }

    public function withSpan($name, callable $callback, array $options = array())
    {
        return call_user_func($callback, new NoopSpan());
    }

    public function currentSpan()
    {
        return null;
    }

    public function currentSpanContext()
    {
        return SpanContext::invalid();
    }

    public function currentOrRootContext()
    {
        return SpanContext::invalid();
    }
}
