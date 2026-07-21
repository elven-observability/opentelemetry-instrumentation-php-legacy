<?php

namespace Elven\Observability\PhpLegacy\Trace;

use Elven\Observability\PhpLegacy\Privacy\AttributeRedactor;
use Elven\Observability\PhpLegacy\Support\Clock;
use Elven\Observability\PhpLegacy\Support\TelemetryValueLimiter;

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
    private $maxAttributes;
    private $maxAttributeLength;
    private $maxEvents;
    private $maxEventAttributes;
    private $droppedAttributesCount;
    private $droppedEventsCount;

    public function __construct(
        $name,
        SpanContext $context,
        SpanContext $parentContext,
        $kind,
        $sampled,
        AttributeRedactor $redactor,
        $onEnd = null,
        array $limits = array()
    ) {
        $this->name = TelemetryValueLimiter::limit((string) $name, 255);
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
        $this->maxAttributes = self::limit($limits, 'max_attributes', 128);
        $this->maxAttributeLength = self::limit($limits, 'max_attribute_length', 4096);
        $this->maxEvents = self::limit($limits, 'max_events', 64);
        $this->maxEventAttributes = self::limit($limits, 'max_event_attributes', 32);
        $this->droppedAttributesCount = 0;
        $this->droppedEventsCount = 0;
    }

    public function setAttribute($key, $value)
    {
        if ($this->ended) {
            return $this;
        }
        $key = substr((string) $key, 0, 255);
        if ($key === '') {
            return $this;
        }
        if (!array_key_exists($key, $this->attributes) && count($this->attributes) >= $this->maxAttributes) {
            $this->droppedAttributesCount++;
            return $this;
        }
        $limited = TelemetryValueLimiter::limit($value, $this->maxAttributeLength);
        $redacted = $this->redactor->redactValue($key, $limited);
        $this->attributes[$key] = TelemetryValueLimiter::limit($redacted, $this->maxAttributeLength);
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
        if (count($this->events) >= $this->maxEvents) {
            $this->droppedEventsCount++;
            return $this;
        }
        $safeAttributes = array();
        $count = 0;
        foreach ($attributes as $key => $value) {
            if ($count >= $this->maxEventAttributes) {
                break;
            }
            $key = substr((string) $key, 0, 255);
            if ($key === '') {
                continue;
            }
            $limited = TelemetryValueLimiter::limit($value, $this->maxAttributeLength);
            $safeAttributes[$key] = TelemetryValueLimiter::limit(
                $this->redactor->redactValue($key, $limited),
                $this->maxAttributeLength
            );
            $count++;
        }
        $this->events[] = array(
            'name' => substr((string) $name, 0, 255),
            'timeUnixNano' => Clock::nowUnixNano(),
            'attributes' => $safeAttributes,
            'droppedAttributesCount' => max(0, count($attributes) - count($safeAttributes)),
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
        $this->setAttribute('error.type', $class);
        $this->setStatus('ERROR', $class);
        return $this;
    }

    public function setStatus($code, $message = '')
    {
        if ($this->ended) {
            return $this;
        }
        $code = strtoupper((string) $code);
        if (!in_array($code, array('UNSET', 'OK', 'ERROR'), true)) {
            $code = 'UNSET';
        }
        $this->statusCode = $code;
        $this->statusMessage = (string) TelemetryValueLimiter::limit(
            $this->redactor->redactValue('status.message', $message),
            $this->maxAttributeLength
        );
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
            try {
                call_user_func($this->onEnd, $this);
            } catch (\Throwable $ignored) {
            }
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

    public function droppedAttributesCount()
    {
        return $this->droppedAttributesCount;
    }

    public function droppedEventsCount()
    {
        return $this->droppedEventsCount;
    }

    private static function limit(array $limits, $key, $default)
    {
        return isset($limits[$key]) && (int) $limits[$key] >= 0 ? (int) $limits[$key] : $default;
    }
}
