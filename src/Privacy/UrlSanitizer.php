<?php

namespace Elven\Observability\PhpLegacy\Privacy;

final class UrlSanitizer
{
    private function __construct()
    {
    }

    public static function sanitizePath($path)
    {
        $path = (string) $path;
        $segments = explode('/', $path);
        $previousSensitive = false;
        foreach ($segments as $index => $segment) {
            if ($segment === '') {
                continue;
            }
            $decoded = rawurldecode($segment);
            if ($previousSensitive) {
                $segments[$index] = '{redacted}';
                $previousSensitive = false;
                continue;
            }
            if (self::isSensitiveKey($decoded)) {
                $previousSensitive = true;
                continue;
            }
            $segments[$index] = self::sanitizePathSegment($decoded);
        }
        return implode('/', $segments);
    }

    public static function sanitizeUrl($url)
    {
        $parts = parse_url((string) $url);
        if ($parts === false) {
            return self::sanitizePath($url);
        }

        $scheme = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
        $host = isset($parts['host']) ? $parts['host'] : '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = isset($parts['path']) ? self::sanitizePath($parts['path']) : '';
        $query = isset($parts['query']) ? self::sanitizeQuery($parts['query']) : '';

        return $scheme . $host . $port . $path . ($query !== '' ? '?' . $query : '');
    }

    public static function sanitizeQuery($query)
    {
        $pairs = array();
        foreach (explode('&', (string) $query) as $part) {
            if ($part === '') {
                continue;
            }
            $kv = explode('=', $part, 2);
            $key = urldecode($kv[0]);
            $value = isset($kv[1]) ? urldecode($kv[1]) : '';
            if (self::isSensitiveKey($key)) {
                $value = '[REDACTED]';
            } else {
                $value = self::redactSensitiveText($value);
            }
            $pairs[] = rawurlencode($key) . '=' . rawurlencode($value);
        }
        return implode('&', $pairs);
    }

    public static function isSensitiveKey($key)
    {
        return preg_match('/authorization|cookie|token|password|passwd|secret|session|api[-_]?key|bearer|cpf|email|card/i', (string) $key) === 1;
    }

    public static function redactSensitiveText($value)
    {
        $value = (string) $value;
        if ($value === '') {
            return $value;
        }
        // Cheap necessary-condition pre-checks. Each pattern below can only match
        // when its literal anchor (or, for CPF/card, any digit) is present, so
        // skipping the PCRE call otherwise is behaviour-preserving and avoids the
        // regex engine on the common clean value (route names, enums, hostnames).
        if (stripos($value, 'bearer') !== false) {
            $value = preg_replace('/Bearer\\s+[A-Za-z0-9_\\.\\-]+/i', 'Bearer [REDACTED]', $value);
        }
        if (strpos($value, 'eyJ') !== false) {
            $value = preg_replace('/\\beyJ[A-Za-z0-9_\\-]+\\.[A-Za-z0-9_\\-]+\\.[A-Za-z0-9_\\-]+\\b/', '[REDACTED_JWT]', $value);
        }
        if (strpos($value, '@') !== false) {
            $value = preg_replace('/[A-Z0-9._%+\\-]+@[A-Z0-9.\\-]+\\.[A-Z]{2,}/i', '[REDACTED_EMAIL]', $value);
        }
        if (strpbrk($value, '0123456789') !== false) {
            $value = preg_replace('/\\b\\d{3}\\.?\\d{3}\\.?\\d{3}-?\\d{2}\\b/', '[REDACTED_CPF]', $value);
            $value = preg_replace('/\\b(?:\\d[ -]*?){13,19}\\b/', '[REDACTED_CARD]', $value);
        }
        return $value;
    }

    public static function isHighCardinalityValue($value)
    {
        $value = (string) $value;
        return preg_match('/^[0-9a-f]{16,64}$/i', $value) === 1
            || preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}/i', $value) === 1
            || preg_match('/^(?=.*[A-Za-z])(?=.*\\d)[A-Za-z0-9_\\-]{16,}$/', $value) === 1;
    }

    private static function sanitizePathSegment($segment)
    {
        $segment = (string) $segment;
        if (preg_match('/[A-Z0-9._%+\\-]+@[A-Z0-9.\\-]+\\.[A-Z]{2,}/i', $segment) === 1) {
            return '{email}';
        }
        if (preg_match('/\\beyJ[A-Za-z0-9_\\-]+\\.[A-Za-z0-9_\\-]+\\.[A-Za-z0-9_\\-]+\\b/', $segment) === 1) {
            return '{token}';
        }
        if (preg_match('/[0-9]{3}\\.?[0-9]{3}\\.?[0-9]{3}-?[0-9]{2}/', $segment) === 1) {
            return '{cpf}';
        }
        if (preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}/i', $segment) === 1) {
            return '{id}';
        }
        if (preg_match('/\\b\\d{4,}\\b/', $segment) === 1) {
            return '{id}';
        }
        if (self::isHighCardinalityValue($segment)) {
            return '{id}';
        }
        return $segment;
    }
}
