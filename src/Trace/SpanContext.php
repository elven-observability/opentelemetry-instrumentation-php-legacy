<?php

namespace Elven\Observability\PhpLegacy\Trace;

final class SpanContext
{
    private $traceId;
    private $spanId;
    private $traceFlags;
    private $traceState;
    private $remote;
    private $valid;

    public function __construct($traceId, $spanId, $traceFlags = '01', $traceState = '', $remote = false, $valid = true)
    {
        $this->traceId = strtolower((string) $traceId);
        $this->spanId = strtolower((string) $spanId);
        $this->traceFlags = strtolower((string) $traceFlags);
        $this->traceState = (string) $traceState;
        $this->remote = (bool) $remote;
        $this->valid = (bool) $valid
            && self::isValidTraceId($this->traceId)
            && self::isValidSpanId($this->spanId)
            && preg_match('/^[0-9a-f]{2}$/', $this->traceFlags) === 1;
    }

    public static function invalid()
    {
        return new self('', '', '00', '', false, false);
    }

    public static function isValidTraceId($traceId)
    {
        return preg_match('/^[0-9a-f]{32}$/', (string) $traceId) === 1
            && !preg_match('/^0{32}$/', (string) $traceId);
    }

    public static function isValidSpanId($spanId)
    {
        return preg_match('/^[0-9a-f]{16}$/', (string) $spanId) === 1
            && !preg_match('/^0{16}$/', (string) $spanId);
    }

    public function isValid()
    {
        return $this->valid;
    }

    public function traceId()
    {
        return $this->traceId;
    }

    public function spanId()
    {
        return $this->spanId;
    }

    public function traceFlags()
    {
        return $this->traceFlags;
    }

    public function traceState()
    {
        return $this->traceState;
    }

    public function isRemote()
    {
        return $this->remote;
    }

    public function isSampled()
    {
        if (!$this->isValid()) {
            return false;
        }
        return (hexdec($this->traceFlags) & 1) === 1;
    }

    public function traceparent()
    {
        if (!$this->isValid()) {
            return '';
        }
        return '00-' . $this->traceId . '-' . $this->spanId . '-' . $this->traceFlags;
    }
}
