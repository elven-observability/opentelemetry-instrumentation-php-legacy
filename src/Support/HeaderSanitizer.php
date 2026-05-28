<?php

namespace Elven\Observability\PhpLegacy\Support;

final class HeaderSanitizer
{
    private function __construct()
    {
    }

    public static function sanitizeName($name)
    {
        $name = trim((string) $name);
        return preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]{1,128}$/', $name) === 1 ? $name : '';
    }

    public static function sanitizeValue($value)
    {
        return str_replace(array("\r", "\n"), '', (string) $value);
    }

    public static function toHeaderLines(array $headers)
    {
        $lines = array();
        foreach ($headers as $key => $value) {
            if (is_int($key)) {
                $line = self::sanitizeHeaderLine($value);
                if ($line !== '') {
                    $lines[] = $line;
                }
                continue;
            }

            $name = self::sanitizeName($key);
            if ($name === '') {
                continue;
            }
            $lines[] = $name . ': ' . self::sanitizeValue($value);
        }
        return $lines;
    }

    public static function headerLinesToMap(array $headerLines)
    {
        $map = array();
        foreach ($headerLines as $line) {
            $line = self::sanitizeHeaderLine($line);
            $parts = explode(':', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }
            $name = self::sanitizeName($parts[0]);
            if ($name === '') {
                continue;
            }
            $map[$name] = trim(self::sanitizeValue($parts[1]));
        }
        return $map;
    }

    private static function sanitizeHeaderLine($line)
    {
        $line = str_replace(array("\r", "\n"), '', (string) $line);
        $parts = explode(':', $line, 2);
        if (count($parts) !== 2 || self::sanitizeName($parts[0]) === '') {
            return '';
        }
        return self::sanitizeName($parts[0]) . ': ' . trim(self::sanitizeValue($parts[1]));
    }
}
