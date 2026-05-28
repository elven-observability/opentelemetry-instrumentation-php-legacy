<?php

namespace Elven\Observability\PhpLegacy\Privacy;

final class DbStatementSanitizer
{
    private function __construct()
    {
    }

    public static function sanitize($statement)
    {
        $sql = (string) $statement;
        $sql = preg_replace('/--.*?(\\r?\\n|$)/', ' ', $sql);
        $sql = preg_replace('#/\\*.*?\\*/#s', ' ', $sql);
        $sql = preg_replace("/'(?:''|[^'])*'/", '?', $sql);
        $sql = preg_replace('/"(?:\\\\"|[^"])*"/', '?', $sql);
        $sql = preg_replace('/\\b\\d+(?:\\.\\d+)?\\b/', '?', $sql);
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
}
