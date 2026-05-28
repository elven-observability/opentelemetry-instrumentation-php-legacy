<?php

namespace Elven\Observability\PhpLegacy\Trace;

use Elven\Observability\PhpLegacy\Privacy\AttributeRedactor;
use Elven\Observability\PhpLegacy\Support\Clock;

final class Span
{
    const KIND_INTERNAL = 'INTERNAL';
    const KIND_SERVER = 'SERVER';
    const KIND_CLIENT = 'CLIENT';
    const KIND_PRODUCER = 'PRODUCER';
    const KIND_CONSUMER = 'CONSUMER';

    private $name;
    private $context;
    private $parentContext;
    private $kind;
    private $startTimeUnixNano;
    private $endTimeUnixNano;
    private $attributes;
    private $events;
    private $statusCode;
    private $statusMessage;
    private $sampled;
    private $ended;
    private $onEnd;
    private $redactor;

    public function __construct(
        $name,
        SpanContext $context,
        SpanContext $parentContext,
        $kind,
        $sampled,
        AttributeRedactor $redactor,
        $onEnd = null
    ) {
        $this->name = (string) $name;
        $this->context = $context;
        $this->parentContext = $parentContext;
        $this->kind = (string) $kind;
        $this->sampled = (bool) $sampled;
        $this->redactor = $redactor;
        $this->startTimeUnixNano = Clock::nowUnixNano();
        $this->endTimeUnixNano = null;
        $this->attributes = array();
        $this->events = array();
        $this->statusCode = 'UNSET';
        $this->statusMessage = '';
        $this->ended = false;
        $this->onEnd = $onEnd;
    }

    public function setAttribute($key, $value)
    {
        if ($this->ended) {
            return $this;
        }
        $this->attributes[(string) $key] = $this->redactor->redactValue((string) $key, $value);
        return $this;
    }

    public function setAttributes(array $attributes)
    {
        foreach ($attributes as $key => $value) {
            $this->setAttribute($key, $value);
        }
        return $this;
    }

    public function addEvent($name, array $attributes = array())
    {
        if ($this->ended) {
            return $this;
        }
        $this->events[] = array(
            'name' => (string) $name,
            'timeUnixNano' => Clock::nowUnixNano(),
            'attributes' => $this->redactor->redactAttributes($attributes),
        );
        return $this;
    }

    public function recordException($throwable)
    {
        $class = is_object($throwable) ? get_class($throwable) : 'exception';
        $message = is_object($throwable) && method_exists($throwable, 'getMessage') ? $throwable->getMessage() : '';
        $this->addEvent('exception', array(
            'exception.type' => $class,
            'exception.message' => $message,
        ));
        $this->setStatus('ERROR', $class);
        return $this;
    }

    public function setStatus($code, $message = '')
    {
        $code = strtoupper((string) $code);
        if (!in_array($code, array('UNSET', 'OK', 'ERROR'), true)) {
            $code = 'UNSET';
        }
        $this->statusCode = $code;
        $this->statusMessage = (string) $this->redactor->redactValue('status.message', $message);
        return $this;
    }

    public function end()
    {
        if ($this->ended) {
            return;
        }
        $this->ended = true;
        $this->endTimeUnixNano = Clock::nowUnixNano();
        if (is_callable($this->onEnd)) {
            call_user_func($this->onEnd, $this);
        }
    }

    public function isEnded()
    {
        return $this->ended;
    }

    public function isSampled()
    {
        return $this->sampled;
    }

    public function context()
    {
        return $this->context;
    }

    public function parentContext()
    {
        return $this->parentContext;
    }

    public function name()
    {
        return $this->name;
    }

    public function kind()
    {
        return $this->kind;
    }

    public function startTimeUnixNano()
    {
        return $this->startTimeUnixNano;
    }

    public function endTimeUnixNano()
    {
        return $this->endTimeUnixNano ?: Clock::nowUnixNano();
    }

    public function attributes()
    {
        return $this->attributes;
    }

    public function events()
    {
        return $this->events;
    }

    public function statusCode()
    {
        return $this->statusCode;
    }

    public function statusMessage()
    {
        return $this->statusMessage;
    }
}
