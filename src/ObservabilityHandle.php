<?php

namespace Elven\Observability\PhpLegacy;

use Elven\Observability\PhpLegacy\Config\ObservabilityConfig;
use Elven\Observability\PhpLegacy\Metrics\MetricFacade;
use Elven\Observability\PhpLegacy\Trace\SpanProcessor;

final class ObservabilityHandle
{
    private $config;
    private $resource;
    private $spanProcessor;
    private $metrics;
    private $shutdown;

    public function __construct(
        ObservabilityConfig $config,
        array $resource,
        SpanProcessor $spanProcessor,
        MetricFacade $metrics
    ) {
        $this->config = $config;
        $this->resource = $resource;
        $this->spanProcessor = $spanProcessor;
        $this->metrics = $metrics;
        $this->shutdown = false;
    }

    public function forceFlush()
    {
        try {
            $spansOk = $this->spanProcessor->forceFlush();
            $metricsOk = $this->metrics->forceFlush();
            return $spansOk && $metricsOk;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function shutdown()
    {
        if ($this->shutdown) {
            return true;
        }
        try {
            $this->metrics->gauge('elven.php.request.memory.peak')->set(memory_get_peak_usage(true));
            $ok = $this->forceFlush();
            $this->shutdown = $ok;
            return $ok;
        } catch (\Throwable $e) {
            return false;
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
