<?php

namespace Elven\Observability\PhpLegacy\Trace;

use Elven\Observability\PhpLegacy\Export\OtlpHttpJsonTraceExporter;
use Elven\Observability\PhpLegacy\Metrics\MetricFacade;

final class SpanProcessor
{
    private $exporter;
    private $metrics;
    private $maxSpans;
    private $spans;
    private $dropped;

    public function __construct($exporter, MetricFacade $metrics, $maxSpans)
    {
        $this->exporter = $exporter instanceof OtlpHttpJsonTraceExporter ? $exporter : null;
        $this->metrics = $metrics;
        $this->maxSpans = (int) $maxSpans;
        $this->spans = array();
        $this->dropped = 0;
    }

    public function onEnd(Span $span)
    {
        if (!$this->exporter) {
            return;
        }
        if (!$span->isSampled()) {
            return;
        }
        if (count($this->spans) >= $this->maxSpans) {
            $this->dropped++;
            $this->metrics->counter('elven.php.exporter.dropped_spans')->add(1);
            return;
        }
        $this->spans[] = $span;
    }

    public function forceFlush()
    {
        if (!$this->exporter || count($this->spans) === 0) {
            $this->spans = array();
            return true;
        }

        $spans = $this->spans;
        $this->spans = array();
        $ok = $this->exporter->export($spans);
        if (!$ok) {
            $this->spans = array_slice($spans, 0, $this->maxSpans);
            $this->metrics->counter('elven.php.exporter.failed_exports')->add(1, array('operation' => 'traces'));
        }
        return $ok;
    }

    public function droppedCount()
    {
        return $this->dropped;
    }
}
