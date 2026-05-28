<?php

namespace Elven\Observability\PhpLegacy\Logs;

use Elven\Observability\PhpLegacy\Observability;
use Monolog\Handler\AbstractProcessingHandler;

final class MonologOtlpHandler extends AbstractProcessingHandler
{
    protected function write(array $record)
    {
        try {
            Observability::logs()->emitMonologRecord($record);
        } catch (\Throwable $e) {
            // Telemetry must never break application logging.
        }
    }
}
