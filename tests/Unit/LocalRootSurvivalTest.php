<?php

namespace Elven\Observability\PhpLegacy\Tests\Unit;

use Elven\Observability\PhpLegacy\Config\EnvConfigResolver;
use Elven\Observability\PhpLegacy\Export\OtlpHttpJsonTraceExporter;
use Elven\Observability\PhpLegacy\Metrics\MetricFacade;
use Elven\Observability\PhpLegacy\Privacy\AttributeRedactor;
use Elven\Observability\PhpLegacy\Resource\ResourceBuilder;
use Elven\Observability\PhpLegacy\Tests\Support\Env;
use Elven\Observability\PhpLegacy\Trace\Sampler\ParentBasedTraceIdRatioSampler;
use Elven\Observability\PhpLegacy\Trace\Span;
use Elven\Observability\PhpLegacy\Trace\SpanContext;
use Elven\Observability\PhpLegacy\Trace\SpanProcessor;
use Elven\Observability\PhpLegacy\Trace\Tracer;
use PHPUnit\Framework\TestCase;

/**
 * A parent span ends AFTER its children: every child ends inside the callback
 * that the parent wraps, so the local root (parent invalid or remote) is the LAST
 * span of its unit of work to end. The per-request span budget used to throw
 * parents away while their children were exported: children fill the buffer and
 * the parents that end after them are dropped (the local root, and an
 * intermediate parent such as the INTERNAL span that wraps the DB writes inside a
 * CONSUMER). Every exported child then points at a parentSpanId that does not
 * exist in the trace (orphans in Tempo).
 *
 * With the reserve the buffer can hold max_spans + PARENT_RESERVE, so the export
 * retry must keep capacity(): slicing it back to max_spans cuts the reserved
 * parents at the tail of the batch.
 */
final class LocalRootSurvivalTest extends TestCase
{
    const REMOTE_TRACE_ID = '4bf92f3577b34da6a3ce929d0e0e4736';
    const REMOTE_SPAN_ID = '00f067aa0ba902b7';

    protected function setUp(): void
    {
        Env::reset();
    }

    protected function tearDown(): void
    {
        Env::reset();
    }

    public function testRootWithRemoteParentSurvivesAFullBuffer(): void
    {
        $client = self::recordingClient();
        list($tracer, $processor) = $this->tracerWithBudget(4, $client);

        $root = $tracer->startSpan('Message consume queue', array(
            'kind' => Span::KIND_CONSUMER,
            'parent_context' => self::remoteParent(),
        ));
        $children = array();
        for ($i = 0; $i < 4; $i++) {
            $child = $tracer->startSpan('SELECT');
            $child->end();
            $children[] = $child;
        }
        $root->end();

        self::assertTrue($processor->forceFlush());
        $exported = $client->spansById();

        self::assertArrayHasKey(
            $root->context()->spanId(),
            $exported,
            'the consumer root must be exported even when its children filled the budget'
        );
        self::assertSame(self::REMOTE_SPAN_ID, $exported[$root->context()->spanId()]['parentSpanId']);
        foreach ($children as $child) {
            self::assertSame(
                $root->context()->spanId(),
                $exported[$child->context()->spanId()]['parentSpanId']
            );
        }
        self::assertSame(0, $processor->droppedCount());
    }

    /**
     * The shape of the zupper-api consumer (ReservationFacade::startPersistSpan()):
     * an INTERNAL span opened inside the CONSUMER around the DB writes. It is not a
     * local root, so a reserve that only knows roots drops it while the INSERTs
     * that point at it are exported.
     */
    public function testIntermediateParentIsNotDroppedWhileItsChildrenAreExported(): void
    {
        $client = self::recordingClient();
        list($tracer, $processor) = $this->tracerWithBudget(3, $client);

        $root = $tracer->startSpan('Message consume processReservationQueue', array(
            'kind' => Span::KIND_CONSUMER,
            'parent_context' => self::remoteParent(),
        ));
        $persist = $tracer->startSpan('zupper.reservation.persist');
        for ($i = 0; $i < 3; $i++) {
            $tracer->startSpan('INSERT')->end();
        }
        $persist->end();
        $root->end();

        self::assertTrue($processor->forceFlush());
        $exported = $client->spansById();

        self::assertSame(array(), self::orphans($exported), 'exported spans pointing at a parent that was dropped');
        self::assertArrayHasKey($persist->context()->spanId(), $exported);
        self::assertSame($root->context()->spanId(), $exported[$persist->context()->spanId()]['parentSpanId']);
        self::assertCount(3 + 2, $exported);
        self::assertSame(0, $processor->droppedCount());
    }

    /**
     * Admitting a parent through the reserve makes ITS parent eligible too, so the
     * chain holds at any depth, not only right above the spans that filled the
     * budget.
     */
    public function testEveryAncestorOfABufferedSpanSurvivesAFullBuffer(): void
    {
        $client = self::recordingClient();
        list($tracer, $processor) = $this->tracerWithBudget(3, $client);

        $root = $tracer->startSpan('job.run');
        $batch = $tracer->startSpan('batch');
        $persist = $tracer->startSpan('zupper.reservation.persist');
        for ($i = 0; $i < 3; $i++) {
            $tracer->startSpan('INSERT')->end();
        }
        $persist->end();
        $batch->end();
        $root->end();

        self::assertTrue($processor->forceFlush());
        $exported = $client->spansById();

        self::assertSame(array(), self::orphans($exported), 'exported spans pointing at a parent that was dropped');
        self::assertArrayHasKey($batch->context()->spanId(), $exported);
        self::assertCount(3 + 3, $exported);
    }

    public function testChildrenBeyondTheBudgetAreStillDropped(): void
    {
        $client = self::recordingClient();
        list($tracer, $processor) = $this->tracerWithBudget(3, $client);

        // No parent at all: a CLI job that opens a brand-new trace.
        $root = $tracer->startSpan('job.run');
        for ($i = 0; $i < 10; $i++) {
            $tracer->startSpan('SELECT')->end();
        }
        $root->end();

        self::assertTrue($processor->forceFlush());
        $exported = $client->spansById();

        self::assertCount(3 + 1, $exported, 'the reserve admits the root, never a child');
        self::assertArrayHasKey($root->context()->spanId(), $exported);
        self::assertSame(7, $processor->droppedCount());
    }

    /**
     * The reserve is for the parents that exported spans point at. A parent whose
     * children were all dropped does not need it, and does not take it.
     */
    public function testParentWithoutBufferedChildrenDoesNotTakeTheReserve(): void
    {
        $client = self::recordingClient();
        list($tracer, $processor) = $this->tracerWithBudget(2, $client);

        $root = $tracer->startSpan('job.run');
        $tracer->startSpan('SELECT')->end();
        $tracer->startSpan('SELECT')->end();
        $persist = $tracer->startSpan('zupper.reservation.persist');
        for ($i = 0; $i < 3; $i++) {
            $tracer->startSpan('INSERT')->end();
        }
        $persist->end();
        $root->end();

        self::assertTrue($processor->forceFlush());
        $exported = $client->spansById();

        self::assertArrayNotHasKey($persist->context()->spanId(), $exported);
        self::assertArrayHasKey($root->context()->spanId(), $exported);
        self::assertCount(2 + 1, $exported);
        self::assertSame(3 + 1, $processor->droppedCount());
        self::assertSame(array(), self::orphans($exported));
    }

    public function testReserveForRootsIsBounded(): void
    {
        $client = self::recordingClient();
        list($tracer, $processor) = $this->tracerWithBudget(1, $client);

        $tracer->startSpan('warm-up')->end();
        // Roots with no child in the buffer: only isLocalRoot() admits them. Half
        // have a remote parent (a CONSUMER), half no parent at all (a CLI job).
        for ($i = 0; $i < 30; $i++) {
            if ($i % 2 === 0) {
                $tracer->startSpan('Message consume queue', array(
                    'kind' => Span::KIND_CONSUMER,
                    'parent_context' => self::remoteParent(),
                ))->end();
            } else {
                $tracer->startSpan('job.run')->end();
            }
        }

        self::assertTrue($processor->forceFlush());

        // max_spans (1) + PARENT_RESERVE (16); the other 14 roots are dropped.
        self::assertCount(1 + 16, $client->spansById());
        self::assertSame(30 - 16, $processor->droppedCount());
    }

    /**
     * Here the root sits in the reserve, at the tail of a batch of max_spans + 1.
     * The retry must keep capacity(): slicing it back to max_spans cuts exactly the
     * reserved span. Before the reserve the buffer never passed max_spans and that
     * slice cut nothing.
     */
    public function testFailedExportDoesNotSliceTheRootOffTheRetry(): void
    {
        $client = self::recordingClient();
        list($tracer, $processor) = $this->tracerWithBudget(3, $client);

        $root = $tracer->startSpan('Message consume queue', array(
            'kind' => Span::KIND_CONSUMER,
            'parent_context' => self::remoteParent(),
        ));
        for ($i = 0; $i < 3; $i++) {
            $tracer->startSpan('SOAP ReservarAereo')->end();
        }
        $root->end();

        $client->failNext = true;
        self::assertFalse($processor->forceFlush(), 'pre-condition: the first export fails');

        self::assertTrue($processor->forceFlush());
        $retried = $client->spansById();

        self::assertCount(3 + 1, $retried);
        self::assertArrayHasKey(
            $root->context()->spanId(),
            $retried,
            'the retry must keep the root that sits at the tail of the batch'
        );
    }

    /**
     * A failed export puts the batch back and rebuilds the parent index from it, so
     * a parent that is still open when the export fails keeps its claim to the
     * reserve when it ends.
     */
    public function testParentStillOpenAcrossAFailedExportKeepsItsReserve(): void
    {
        $client = self::recordingClient();
        list($tracer, $processor) = $this->tracerWithBudget(3, $client);

        $root = $tracer->startSpan('job.run');
        $batch = $tracer->startSpan('batch');
        for ($i = 0; $i < 3; $i++) {
            $tracer->startSpan('SELECT')->end();
        }

        $client->failNext = true;
        self::assertFalse($processor->forceFlush(), 'pre-condition: the export fails while the parent is open');

        $batch->end();
        $root->end();

        self::assertTrue($processor->forceFlush());
        $exported = $client->spansById();

        self::assertSame(array(), self::orphans($exported), 'exported spans pointing at a parent that was dropped');
        self::assertArrayHasKey($batch->context()->spanId(), $exported);
        self::assertCount(3 + 2, $exported);
    }

    /**
     * The index lives exactly as long as the buffer: a long-running worker that
     * flushes after every unit of work does not accumulate span ids.
     */
    public function testSuccessfulFlushEmptiesTheParentIndex(): void
    {
        $client = self::recordingClient();
        list($tracer, $processor) = $this->tracerWithBudget(64, $client);

        for ($unit = 0; $unit < 3; $unit++) {
            $root = $tracer->startSpan('Message consume queue', array(
                'kind' => Span::KIND_CONSUMER,
                'parent_context' => self::remoteParent(),
            ));
            $persist = $tracer->startSpan('zupper.reservation.persist');
            $tracer->startSpan('INSERT')->end();
            $persist->end();
            $root->end();
            self::assertNotSame(array(), self::getPrivate($processor, 'bufferedParents'), 'pre-condition: indexed');

            self::assertTrue($processor->forceFlush());
            self::assertSame(array(), self::getPrivate($processor, 'bufferedParents'));
        }
        self::assertCount(3 * 3, $client->spansById());
    }

    /**
     * @return array{0: Tracer, 1: SpanProcessor}
     */
    private function tracerWithBudget($maxSpans, $client)
    {
        $config = EnvConfigResolver::resolve(array('service_name' => 'local-root-test'));
        $redactor = new AttributeRedactor($config);
        $exporter = new OtlpHttpJsonTraceExporter($config, ResourceBuilder::build($config));
        self::setPrivate($exporter, 'client', $client);
        $processor = new SpanProcessor($exporter, new MetricFacade(null, $redactor), $maxSpans);
        $tracer = new Tracer(
            new ParentBasedTraceIdRatioSampler(1.0),
            $processor,
            $redactor,
            SpanContext::invalid()
        );

        return array($tracer, $processor);
    }

    /**
     * @param array<string, array> $exported
     * @return array<int, string> "name -> parentSpanId" of every exported span whose
     *                            local parent is not in the export
     */
    private static function orphans(array $exported)
    {
        $orphans = array();
        foreach ($exported as $span) {
            if (!isset($span['parentSpanId']) || $span['parentSpanId'] === self::REMOTE_SPAN_ID) {
                continue;
            }
            if (!isset($exported[$span['parentSpanId']])) {
                $orphans[] = $span['name'] . ' -> ' . $span['parentSpanId'];
            }
        }
        return $orphans;
    }

    private static function remoteParent()
    {
        return new SpanContext(self::REMOTE_TRACE_ID, self::REMOTE_SPAN_ID, '01', '', true, true);
    }

    private static function recordingClient()
    {
        return new class {
            /** @var array<int, array> */
            public $payloads = array();
            /** @var bool */
            public $failNext = false;

            public function post($url, array $payload)
            {
                if ($this->failNext) {
                    $this->failNext = false;
                    return false;
                }
                $this->payloads[] = $payload;
                return true;
            }

            public function spansById()
            {
                $spans = array();
                foreach ($this->payloads as $payload) {
                    foreach ($payload['resourceSpans'][0]['scopeSpans'][0]['spans'] as $span) {
                        $spans[$span['spanId']] = $span;
                    }
                }
                return $spans;
            }
        };
    }

    private static function getPrivate($object, $property)
    {
        $reflection = new \ReflectionProperty(get_class($object), $property);
        $reflection->setAccessible(true);
        return $reflection->getValue($object);
    }

    private static function setPrivate($object, $property, $value)
    {
        $reflection = new \ReflectionProperty(get_class($object), $property);
        $reflection->setAccessible(true);
        $reflection->setValue($object, $value);
    }
}
