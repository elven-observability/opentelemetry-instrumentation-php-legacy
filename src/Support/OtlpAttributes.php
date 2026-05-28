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
        return array('stringValue' => (string) $value);
    }
}
