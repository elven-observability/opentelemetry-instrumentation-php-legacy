<?php

namespace Elven\Observability\PhpLegacy\Support;

final class Clock
{
    private function __construct()
    {
    }

    public static function nowUnixNano()
    {
        $parts = explode(' ', microtime());
        $fraction = isset($parts[0]) ? (float) $parts[0] : 0.0;
        $seconds = isset($parts[1]) ? (int) $parts[1] : time();
        $nanos = ($seconds * 1000000000) + (int) round($fraction * 1000000000);
        return (string) $nanos;
    }
}
