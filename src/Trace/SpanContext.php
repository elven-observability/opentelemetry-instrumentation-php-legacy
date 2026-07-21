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
        $this->traceState = self::sanitizeTraceState($traceState);
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

    private static function sanitizeTraceState($traceState)
    {
        $traceState = trim((string) $traceState);
        if ($traceState === '' || strlen($traceState) > 512 || preg_match('/[\x00-\x20\x7f]/', $traceState)) {
            return '';
        }
        $members = explode(',', $traceState);
        if (count($members) > 32) {
            return '';
        }
        $safeMembers = array();
        foreach ($members as $member) {
            $member = trim($member);
            if (preg_match('/^[a-z0-9][a-z0-9_\-*\/]{0,255}(?:@[a-z0-9][a-z0-9_\-*\/]{0,13})?=[\x21-\x2b\x2d-\x3c\x3e-\x7e]{1,256}$/', $member) !== 1) {
                return '';
            }
            $safeMembers[] = $member;
        }
        return implode(',', $safeMembers);
    }
}
