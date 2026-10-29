<?php

namespace Elven\Observability\PhpLegacy\Export;

/** Bound traversal and output before constructing the JSON request string. */
final class LimitedJson
{
    public static function encode($value, $limit = ExportBudget::WIRE_BYTES)
    {
        $remaining = (int) $limit;
        $nodes = 65536;
        try {
            return self::value($value, $remaining, $nodes, 0);
        } catch (\Throwable $ignored) {
            return null;
        }
    }

    private static function value($value, &$remaining, &$nodes, $depth)
    {
        if (--$nodes < 0 || $depth > 32 || $remaining < 2) {
            throw new \RuntimeException('OTLP JSON budget exceeded');
        }
        if (!is_array($value)) {
            if (is_object($value) || is_resource($value) || (is_string($value) && strlen($value) > $remaining)) {
                throw new \RuntimeException('Invalid or oversized OTLP value');
            }
            $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($json === false || strlen($json) > $remaining) {
                throw new \RuntimeException('OTLP JSON budget exceeded');
            }
            $remaining -= strlen($json);
            return $json;
        }
        if (count($value) > $nodes) {
            throw new \RuntimeException('OTLP JSON node budget exceeded');
        }
        $index = 0;
        $list = true;
        foreach ($value as $key => $ignored) {
            if ($key !== $index++) {
                $list = false;
                break;
            }
        }
        $remaining -= 2;
        $json = $list ? '[' : '{';
        $first = true;
        foreach ($value as $key => $item) {
            if (!$first) {
                $remaining--;
                $json .= ',';
            }
            $first = false;
            if (!$list) {
                $json .= self::value((string) $key, $remaining, $nodes, $depth + 1) . ':';
                $remaining--;
            }
            $json .= self::value($item, $remaining, $nodes, $depth + 1);
        }
        return $json . ($list ? ']' : '}');
    }
}
