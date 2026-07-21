<?php

namespace Elven\Observability\PhpLegacy\Trace;

use Elven\Observability\PhpLegacy\Privacy\AttributeRedactor;
use Elven\Observability\PhpLegacy\Support\IdGenerator;
use Elven\Observability\PhpLegacy\Trace\Sampler\SamplerInterface;

final class Tracer
{
    private $sampler;
    private $processor;
    private $redactor;
    private $rootParentContext;
    private $spanStack;
    private $limits;

    public function __construct(
        SamplerInterface $sampler,
        SpanProcessor $processor,
        AttributeRedactor $redactor,
        SpanContext $rootParentContext,
        array $limits = array()
    ) {
        $this->sampler = $sampler;
        $this->processor = $processor;
        $this->redactor = $redactor;
        $this->rootParentContext = $rootParentContext;
        $this->spanStack = array();
        $this->limits = $limits;
    }

    public function startSpan($name, array $options = array())
    {
        try {
            $parent = isset($options['parent_context']) && $options['parent_context'] instanceof SpanContext
                ? $options['parent_context']
                : $this->currentSpanContext();
            if (!$parent->isValid() && !isset($options['parent_context'])) {
                $parent = $this->rootParentContext;
            }
            $traceId = $parent->isValid() ? $parent->traceId() : IdGenerator::traceId();
            $spanId = IdGenerator::spanId();
            $sampled = $this->sampler->shouldSample(
                $traceId,
                $parent,
                $name,
                isset($options['attributes']) ? $options['attributes'] : array()
            );
            $flags = $sampled ? '01' : '00';
            $context = new SpanContext($traceId, $spanId, $flags, $parent->traceState(), false, true);
            $kind = isset($options['kind']) ? $options['kind'] : Span::KIND_INTERNAL;
            $attributes = isset($options['attributes']) && is_array($options['attributes'])
                ? $options['attributes']
                : array();

            $span = new Span(
                $name,
                $context,
                $parent,
                $kind,
                $sampled,
                $this->redactor,
                array($this, 'onSpanEnd'),
                $this->limits
            );
            $span->setAttributes($attributes);
            $this->spanStack[] = $span;
            return $span;
        } catch (\Throwable $e) {
            return new NoopSpan();
        }
    }

    public function withSpan($name, callable $callback, array $options = array())
    {
        $span = $this->startSpan($name, $options);
        try {
            return call_user_func($callback, $span);
        } catch (\Throwable $e) {
            try {
                $span->recordException($e);
            } catch (\Throwable $ignored) {
            }
            throw $e;
        } finally {
            try {
                if (!$span->isEnded()) {
                    $span->end();
                }
            } catch (\Throwable $ignored) {
            }
        }
    }

    public function currentSpan()
    {
        if (!$this->spanStack) {
            return null;
        }
        return $this->spanStack[count($this->spanStack) - 1];
    }

    public function currentSpanContext()
    {
        $span = $this->currentSpan();
        if ($span instanceof Span) {
            return $span->context();
        }
        return SpanContext::invalid();
    }

    public function currentOrRootContext()
    {
        $current = $this->currentSpanContext();
        return $current->isValid() ? $current : $this->rootParentContext;
    }

    public function setRootParentContext(SpanContext $context)
    {
        $this->rootParentContext = $context;
    }

    public function clearActiveSpans()
    {
        $this->spanStack = array();
    }

    public function deactivateSpan($span)
    {
        if ($span instanceof Span) {
            $this->removeFromStack($span);
        }
    }

    public function onSpanEnd(Span $span)
    {
        $this->removeFromStack($span);
        try {
            $this->processor->onEnd($span);
        } catch (\Throwable $ignored) {
        }
    }

    private function removeFromStack(Span $span)
    {
        for ($i = count($this->spanStack) - 1; $i >= 0; $i--) {
            if ($this->spanStack[$i] === $span) {
                array_splice($this->spanStack, $i, 1);
                return;
            }
        }
    }
}
