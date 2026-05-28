<?php

namespace Elven\Observability\PhpLegacy\Logs;

use Elven\Observability\PhpLegacy\Observability;

final class MonologTraceProcessor
{
    public function __invoke(array $record)
    {
        if (!isset($record['extra']) || !is_array($record['extra'])) {
            $record['extra'] = array();
        }
        $record['extra'] = Observability::logs()->correlate($record['extra']);
        return $record;
    }
}
