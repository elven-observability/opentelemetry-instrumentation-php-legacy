<?php

namespace Elven\Observability\PhpLegacy\Support;

final class OtlpAttributes
{
    private function __construct()
    {
    }

    public static function encode(array $attributes)
    {
        $encoded = array();
        foreach ($attributes as $key => $value) {
            if ($value === null || $key === '') {
                continue;
            }
            $encoded[] = array(
                'key' => (string) $key,
                'value' => self::value($value),
            );
        }
        return $encoded;
    }

    public static function value($value)
    {
        if (is_bool($value)) {
            return array('boolValue' => $value);
        }
        if (is_int($value)) {
            return array('intValue' => (string) $value);
        }
        if (is_float($value)) {
            return array('doubleValue' => $value);
        }
        if (is_array($value)) {
            $values = array();
            foreach ($value as $item) {
                if (is_scalar($item) || $item === null) {
                    $values[] = self::value($item);
                }
            }
            return array('arrayValue' => array('values' => $values));
        }
        if (is_object($value)) {
            if (method_exists($value, '__toString')) {
                return array('stringValue' => (string) $value);
            }
            return array('stringValue' => '[object ' . get_class($value) . ']');
        }
        if (is_resource($value)) {
            return array('stringValue' => '[resource ' . get_resource_type($value) . ']');
        }
        return array('stringValue' => (string) $value);
    }
}
