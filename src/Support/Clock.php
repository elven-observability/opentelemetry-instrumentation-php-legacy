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
        $seconds = isset($parts[1]) && preg_match('/^\d+$/', $parts[1]) === 1
            ? ltrim($parts[1], '0')
            : (string) time();
        $seconds = $seconds === '' ? '0' : $seconds;
        $fraction = isset($parts[0]) ? preg_replace('/\D/', '', substr($parts[0], 2)) : '';
        $nanoseconds = str_pad(substr((string) $fraction, 0, 9), 9, '0');
        return $seconds . $nanoseconds;
    }
}
