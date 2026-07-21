<?php

namespace Elven\Observability\PhpLegacy\Support;

final class TelemetryValueLimiter
{
    private function __construct()
    {
    }

    public static function limit($value, $maxBytes, $maxArrayItems = 32)
    {
        $maxBytes = max(16, (int) $maxBytes);
        if (is_string($value)) {
            return self::truncate($value, $maxBytes);
        }
        if (is_array($value)) {
            $safe = array();
            $count = 0;
            $usedBytes = 0;
            foreach ($value as $item) {
                if ($count >= $maxArrayItems) {
                    break;
                }
                if (is_scalar($item) || $item === null) {
                    $remaining = $maxBytes - $usedBytes;
                    if ($remaining <= 0) {
                        break;
                    }
                    if (is_string($item)) {
                        $item = self::truncate($item, $remaining);
                        $usedBytes += strlen($item);
                    } else {
                        $usedBytes += strlen((string) $item);
                    }
                    $safe[] = $item;
                    $count++;
                }
            }
            return $safe;
        }
        if (is_object($value)) {
            try {
                return method_exists($value, '__toString')
                    ? self::truncate((string) $value, $maxBytes)
                    : '[object ' . get_class($value) . ']';
            } catch (\Throwable $ignored) {
                return '[object]';
            }
        }
        if (is_resource($value)) {
            return '[resource ' . get_resource_type($value) . ']';
        }
        return $value;
    }

    private static function truncate($value, $maxBytes)
    {
        if (strlen($value) <= $maxBytes) {
            return $value;
        }
        return substr($value, 0, max(0, $maxBytes - 14)) . '...[truncated]';
    }
}
