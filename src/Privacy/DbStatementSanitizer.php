<?php

namespace Elven\Observability\PhpLegacy\Privacy;

final class DbStatementSanitizer
{
    const MAX_STATEMENT_BYTES = 65536;

    private function __construct()
    {
    }

    public static function sanitize($statement)
    {
        $sql = self::stripQuotedValuesAndComments(substr((string) $statement, 0, self::MAX_STATEMENT_BYTES));
        $sql = preg_replace('/\\b0x[0-9a-f]+\\b/i', '?', $sql);
        $sql = preg_replace('/\\b\\d+(?:\\.\\d+)?(?:e[+\\-]?\\d+)?\\b/i', '?', $sql);
        $sql = preg_replace('/\\s+/', ' ', $sql);
        return trim($sql);
    }

    public static function summary($statement)
    {
        $sql = self::sanitize($statement);
        if ($sql === '') {
            return 'UNKNOWN';
        }
        $operation = strtoupper(strtok($sql, " \t\n\r"));
        $target = '';
        if (preg_match('/\\b(?:from|into|update|join)\\s+([a-zA-Z0-9_\\.]+)/i', $sql, $match)) {
            $target = ' ' . strtolower($match[1]);
        }
        return trim($operation . $target);
    }

    private static function stripQuotedValuesAndComments($sql)
    {
        $safe = '';
        $length = strlen($sql);
        $index = 0;
        while ($index < $length) {
            $character = $sql[$index];
            $next = $index + 1 < $length ? $sql[$index + 1] : '';

            if (($character === '-' && $next === '-') || $character === '#') {
                $newline = strpos($sql, "\n", $index + ($character === '#' ? 1 : 2));
                $index = $newline === false ? $length : $newline + 1;
                $safe .= ' ';
                continue;
            }
            if ($character === '/' && $next === '*') {
                $end = strpos($sql, '*/', $index + 2);
                $index = $end === false ? $length : $end + 2;
                $safe .= ' ';
                continue;
            }
            if ($character === '\'' || $character === '"') {
                $index = self::skipQuoted($sql, $index, $character);
                $safe .= '?';
                continue;
            }
            if ($character === '$') {
                $delimiter = self::dollarQuoteDelimiter(substr($sql, $index));
                if ($delimiter !== '') {
                    $end = strpos($sql, $delimiter, $index + strlen($delimiter));
                    $index = $end === false ? $length : $end + strlen($delimiter);
                    $safe .= '?';
                    continue;
                }
            }

            $safe .= $character;
            $index++;
        }
        return $safe;
    }

    private static function skipQuoted($sql, $index, $quote)
    {
        $length = strlen($sql);
        $index++;
        while ($index < $length) {
            if ($sql[$index] === '\\') {
                $index += min(2, $length - $index);
                continue;
            }
            if ($sql[$index] === $quote) {
                if ($index + 1 < $length && $sql[$index + 1] === $quote) {
                    $index += 2;
                    continue;
                }
                return $index + 1;
            }
            $index++;
        }
        return $length;
    }

    private static function dollarQuoteDelimiter($value)
    {
        if (preg_match('/^(\\$(?:[A-Za-z_][A-Za-z0-9_]*)?\\$)/', (string) $value, $match) === 1) {
            return $match[0];
        }
        return '';
    }
}
