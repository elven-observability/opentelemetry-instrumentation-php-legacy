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
                // A key word marks the NEXT segment as its value, but this segment
                // is still a value the consumer sent: `cpf_12345678901` and
                // `session_20260910` used to skip every identifier check below and
                // went through raw. It stays a key either way.
                $sanitized = self::sanitizePathSegment($decoded);
                if ($sanitized !== $decoded) {
                    $segments[$index] = $sanitized;
                }
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
            // Not `\b`: `_` is a word character, so `token_eyJ...` has no word
            // boundary before the JWT and went through raw. Same class of defect as
            // the old `\b\d{4,}\b` digit check.
            $value = preg_replace('/(?<![A-Za-z0-9])eyJ[A-Za-z0-9_\\-]+\\.[A-Za-z0-9_\\-]+\\.[A-Za-z0-9_\\-]+/', '[REDACTED_JWT]', $value);
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
            || self::looksLikeOpaqueToken($value);
    }

    /**
     * A long letter+digit token that is NOT made of words.
     *
     * The previous rule was "16+ characters of [A-Za-z0-9_-] with a letter and a
     * digit". That also matched names that carry a small number, which are exactly
     * the values a label exists to keep: `Missing404Exception` (the Elasticsearch
     * client's exception for a missing index) became `{id}` in a consumer's
     * `error_type`, erasing the only clue a failing job left. So did
     * `allow_bearer_session_recovery_v2` in `operation`.
     *
     * The value still has to pass that gate, and then at least one part between
     * `_`/`-` separators has to be opaque: 8+ characters mixing letters and digits
     * in a way no word does. A part shaped like `letters, up to three digits,
     * letters` (`Missing404Exception`, `Http2ProtocolError`) is word-shaped and does
     * not decide. Runs of four or more digits are caught separately, in any
     * position, by {@see self::sanitizePathSegment()}.
     *
     * Known trade-off, stated rather than hidden: a random token with a single short
     * digit run has the same shape as a word with a number and is not caught here.
     *
     * @param string $value
     * @return bool
     */
    private static function looksLikeOpaqueToken($value)
    {
        if (preg_match('/^(?=.*[A-Za-z])(?=.*\\d)[A-Za-z0-9_\\-]{16,}$/', $value) !== 1) {
            return false;
        }

        foreach (preg_split('/[_\\-]+/', $value) as $part) {
            if (strlen($part) < 8 || preg_match('/[A-Za-z]/', $part) !== 1 || preg_match('/\\d/', $part) !== 1) {
                continue;
            }
            if (preg_match('/^[A-Za-z]*[0-9]{1,3}[A-Za-z]*$/', $part) === 1) {
                continue;
            }
            return true;
        }

        return false;
    }

    private static function sanitizePathSegment($segment)
    {
        $segment = (string) $segment;
        if (preg_match('/[A-Z0-9._%+\\-]+@[A-Z0-9.\\-]+\\.[A-Z]{2,}/i', $segment) === 1) {
            return '{email}';
        }
        // A JWT glued to a word by `_` (`token_eyJ...`) has no word boundary before
        // it; see redactSensitiveText().
        if (preg_match('/(?<![A-Za-z0-9])eyJ[A-Za-z0-9_\\-]+\\.[A-Za-z0-9_\\-]+\\.[A-Za-z0-9_\\-]+/', $segment) === 1) {
            return '{token}';
        }
        // UUID BEFORE the CPF shape: the last group of a UUID is 12 hex characters
        // and, when they are all digits (`446655440000`), the CPF shape matched
        // inside it and `order_550e8400-e29b-41d4-a716-446655440000` was reported
        // as `{cpf}`.
        if (preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}/i', $segment) === 1) {
            return '{id}';
        }
        // The CPF shape still decides that the segment is redacted, exactly as
        // before. It is NAMED `{cpf}` only when it stands alone between non-digits;
        // inside a longer digit run (a 16-digit card number, `123.456.789-100`) it
        // is some other identifier and becomes `{id}` -- never the raw value.
        if (preg_match('/[0-9]{3}\\.?[0-9]{3}\\.?[0-9]{3}-?[0-9]{2}/', $segment) === 1) {
            return preg_match('/(?<![0-9])[0-9]{3}\\.?[0-9]{3}\\.?[0-9]{3}-?[0-9]{2}(?![0-9])/', $segment) === 1
                ? '{cpf}'
                : '{id}';
        }
        // Four or more digits in ANY position. The previous `\b\d{4,}\b` needed a
        // word boundary, and `_` is a word character: `reserva-201211`,
        // `reserva.201211` and `reserva 201211` became `{id}`, but `reserva_201211` --
        // the shape a consumer builds most often -- went through as a label value.
        if (preg_match('/[0-9]{4,}/', $segment) === 1) {
            return '{id}';
        }
        if (self::isHighCardinalityValue($segment)) {
            return '{id}';
        }
        return $segment;
    }
}
