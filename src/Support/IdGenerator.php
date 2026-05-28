<?php

namespace Elven\Observability\PhpLegacy\Support;

final class IdGenerator
{
    private function __construct()
    {
    }

    public static function traceId()
    {
        return self::nonZeroHex(16);
    }

    public static function spanId()
    {
        return self::nonZeroHex(8);
    }

    private static function nonZeroHex($bytes)
    {
        do {
            $hex = bin2hex(self::randomBytes($bytes));
        } while (preg_match('/^0+$/', $hex));

        return $hex;
    }

    private static function randomBytes($bytes)
    {
        try {
            return random_bytes($bytes);
        } catch (\Throwable $e) {
            if (function_exists('openssl_random_pseudo_bytes')) {
                $strong = false;
                $value = openssl_random_pseudo_bytes($bytes, $strong);
                if ($value !== false && strlen($value) === $bytes) {
                    return $value;
                }
            }

            $entropy = uniqid('', true) . getmypid() . mt_rand() . microtime(true);
            return substr(hash('sha256', $entropy, true), 0, $bytes);
        }
    }
}
