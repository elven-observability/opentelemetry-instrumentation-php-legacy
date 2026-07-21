<?php

namespace Elven\Observability\PhpLegacy\Privacy;

final class IdentifierHasher
{
    private static $ephemeralSalt;

    private function __construct()
    {
    }

    public static function hash($value)
    {
        if (!is_scalar($value) || (string) $value === '') {
            return '';
        }
        $salt = getenv('ELVEN_OTEL_ID_HASH_SALT');
        if (!is_string($salt) || $salt === '') {
            $salt = self::ephemeralSalt();
        }
        $digest = hash_hmac('sha256', (string) $value, $salt);
        return substr($digest, 0, 32);
    }

    private static function ephemeralSalt()
    {
        if (is_string(self::$ephemeralSalt) && self::$ephemeralSalt !== '') {
            return self::$ephemeralSalt;
        }
        try {
            self::$ephemeralSalt = random_bytes(32);
        } catch (\Throwable $ignored) {
            self::$ephemeralSalt = hash(
                'sha256',
                php_uname('n') . ':' . getmypid() . ':' . microtime(true) . ':' . mt_rand(),
                true
            );
        }
        return self::$ephemeralSalt;
    }
}
