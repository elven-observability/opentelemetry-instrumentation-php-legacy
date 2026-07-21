<?php

namespace Elven\Observability\PhpLegacy;

use Elven\Observability\PhpLegacy\Config\ObservabilityConfig;
use Elven\Observability\PhpLegacy\Logs\LogsFacade;
use Elven\Observability\PhpLegacy\Metrics\MetricFacade;
use Elven\Observability\PhpLegacy\Support\ShutdownRegistry;
use Elven\Observability\PhpLegacy\Trace\SpanProcessor;

final class ObservabilityHandle
{
    private $config;
    private $resource;
    private $spanProcessor;
    private $logs;
    private $metrics;
    private $shutdown;

    public function __construct(
        ObservabilityConfig $config,
        array $resource,
        SpanProcessor $spanProcessor,
        LogsFacade $logs,
        MetricFacade $metrics
    ) {
        $this->config = $config;
        $this->resource = $resource;
        $this->spanProcessor = $spanProcessor;
        $this->logs = $logs;
        $this->metrics = $metrics;
        $this->shutdown = false;
    }

    public function forceFlush()
    {
        try {
            $spansOk = $this->spanProcessor->forceFlush();
            $logsOk = $this->logs->forceFlush();
            $metricsOk = $this->metrics->forceFlush();
            return $spansOk && $logsOk && $metricsOk;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function shutdown()
    {
        if ($this->shutdown) {
            return true;
        }
        ShutdownRegistry::run();
        $this->shutdown = true;
        try {
            $this->metrics->gauge('elven.php.request.memory.peak')->set(memory_get_peak_usage(true));
            return $this->forceFlush();
        } catch (\Throwable $e) {
            return false;
        } finally {
            try {
                $this->metrics->clearRequestAttributes();
            } catch (\Throwable $ignored) {
            }
        }
    }

    public function config()
    {
        return $this->config;
    }

    public function resource()
    {
        return $this->resource;
    }
}
