<?php

namespace Elven\Observability\PhpLegacy\Trace;

use Elven\Observability\PhpLegacy\Export\OtlpHttpJsonTraceExporter;
use Elven\Observability\PhpLegacy\Metrics\MetricFacade;

final class SpanProcessor
{
    /**
     * Extra slots, above max_spans, for the spans that buffered spans point at.
     *
     * A parent ends AFTER its children, because they end inside the callback it
     * wraps. With a plain "first come" budget, a unit of work that produces
     * max_spans spans fills the buffer and the parents, ending last, are the ones
     * dropped: every exported child then points at a parentSpanId that does not
     * exist in the trace. Only two kinds of span may take a reserve slot:
     *
     *  - a local root (parent invalid or remote: the SERVER span of a request, the
     *    CONSUMER span of a message, the span of a CLI job);
     *  - the parent of a span already in the buffer, at any depth (an INTERNAL span
     *    wrapping DB writes inside a CONSUMER: once it takes a slot, its own parent
     *    becomes eligible in turn).
     *
     * A span with no child in the buffer never takes the reserve, so leaves beyond
     * the budget are still dropped and the buffer stays bounded at
     * max_spans + PARENT_RESERVE.
     *
     * Known limits: the reserve is shared, first come. The parents still open when
     * the budget runs out claim it innermost first, and local roots of later units
     * of work in the same buffer claim it too; past PARENT_RESERVE of them the rest
     * are dropped again, the local root included. A successful flush empties the
     * index together with the buffer, so a parent still open across it no longer
     * counts the children exported in that flush.
     */
    const PARENT_RESERVE = 16;

    private $exporter;
    private $metrics;
    private $maxSpans;
    private $spans;
    private $dropped;

    /**
     * Span ids that a span in the buffer points at as its (local) parent.
     *
     * @var array<string, bool>
     */
    private $bufferedParents = array();

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
            if (
                (self::isLocalRoot($span) || isset($this->bufferedParents[$span->context()->spanId()]))
                && count($this->spans) < $this->capacity()
            ) {
                $this->accept($span);
                return;
            }
            $this->dropped++;
            $this->metrics->counter('elven.php.exporter.dropped_spans')->add(1);
            return;
        }
        $this->accept($span);
    }

    public function forceFlush()
    {
        if (!$this->exporter || count($this->spans) === 0) {
            $this->spans = array();
            $this->bufferedParents = array();
            return true;
        }

        $spans = $this->spans;
        $this->spans = array();
        $this->bufferedParents = array();
        $ok = $this->exporter->export($spans);
        if (!$ok) {
            // With the reserve the buffer can hold max_spans + PARENT_RESERVE; the
            // retry keeps capacity(), because slicing to max_spans would cut the
            // reserved parents at the tail of the batch. Re-accepting rebuilds the
            // index, so a parent still open keeps its claim to the reserve.
            foreach (array_slice($spans, 0, $this->capacity()) as $kept) {
                $this->accept($kept);
            }
            $this->metrics->counter('elven.php.exporter.failed_exports')->add(1, array('operation' => 'traces'));
        }
        return $ok;
    }

    public function droppedCount()
    {
        return $this->dropped;
    }

    private function accept(Span $span)
    {
        $this->spans[] = $span;
        $parent = $span->parentContext();
        if ($parent->isValid() && !$parent->isRemote()) {
            $this->bufferedParents[$parent->spanId()] = true;
        }
    }

    private function capacity()
    {
        return $this->maxSpans + self::PARENT_RESERVE;
    }

    private static function isLocalRoot(Span $span)
    {
        $parent = $span->parentContext();
        return !$parent->isValid() || $parent->isRemote();
    }
}
