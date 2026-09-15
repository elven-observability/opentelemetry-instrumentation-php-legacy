<?php

namespace Elven\Observability\PhpLegacy\Tests\Unit;

use Elven\Observability\PhpLegacy\Config\EnvConfigResolver;
use Elven\Observability\PhpLegacy\Export\OtlpHttpJsonTraceExporter;
use Elven\Observability\PhpLegacy\Instrumentation\HttpServerInstrumentation;
use Elven\Observability\PhpLegacy\Metrics\MetricFacade;
use Elven\Observability\PhpLegacy\Observability;
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
 * span of its unit of work to end. Two paths used to throw parents away while
 * their children were exported, which leaves every child pointing at a
 * parentSpanId that does not exist in the trace (orphans in Tempo):
 *
 *  - the per-request span budget: children fill the buffer, the parents that end
 *    after them are dropped (the local root, and an intermediate parent such as
 *    the INTERNAL span that wraps the DB writes inside a CONSUMER);
 *  - process death (fatal or exit) in the middle of the unit of work: the root is
 *    still open, shutdown() flushes the children and clears the stack.
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

    public function testSpanLeftOpenAtShutdownIsEndedAndExported(): void
    {
        Observability::init(array(
            'service_name' => 'local-root-shutdown-test',
            'metrics_exporter' => 'none',
            'logs_exporter' => 'none',
        ));
        $client = self::recordingClient();
        self::replaceTraceClient($client);
        error_clear_last();

        $tracer = Observability::tracer();
        $root = $tracer->startSpan('Message consume queue', array(
            'kind' => Span::KIND_CONSUMER,
            'parent_context' => self::remoteParent(),
        ));
        $child = $tracer->startSpan('SOAP ReservarAereo');
        $child->end();
        $open = $tracer->startSpan('SELECT');

        // The process dies here: neither $open nor $root reach their end().
        Observability::shutdown();

        $exported = $client->spansById();
        $rootId = $root->context()->spanId();
        self::assertArrayHasKey($rootId, $exported, 'the open consumer span must not be thrown away at shutdown');
        self::assertArrayHasKey($open->context()->spanId(), $exported);
        self::assertSame($rootId, $exported[$child->context()->spanId()]['parentSpanId']);
        self::assertSame($rootId, $exported[$open->context()->spanId()]['parentSpanId']);

        $attributes = self::attributes($exported[$rootId]);
        self::assertTrue($attributes['elven.span.incomplete']);
        self::assertSame('process_shutdown', $attributes['elven.span.end_reason']);
        self::assertSame(
            'STATUS_CODE_UNSET',
            $exported[$rootId]['status']['code'],
            'a deliberate exit is not an error of the span'
        );
        self::assertArrayNotHasKey('elven.span.incomplete', self::attributes($exported[$child->context()->spanId()]));
        self::assertNull($tracer->currentSpan());
    }

    /**
     * The request scope (ShutdownRegistry) must finalize the SERVER span BEFORE
     * endActiveSpans() ends whatever is still open. In the other order every FPM
     * request that ends by exit/die, the case the scope exists for, exports its
     * SERVER span as incomplete and without http.response.status_code.
     */
    public function testServerScopeIsFinalizedBeforeOpenSpansAreEnded(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/rest/v2/order/check';
        Observability::init(array(
            'service_name' => 'local-root-server-scope-test',
            'metrics_exporter' => 'none',
            'logs_exporter' => 'none',
        ));
        $client = self::recordingClient();
        self::replaceTraceClient($client);
        error_clear_last();

        $scope = HttpServerInstrumentation::begin('/rest/v2/order/check', function () {
            return 404;
        });
        $serverId = $scope->span()->context()->spanId();
        $open = Observability::tracer()->startSpan('SELECT');

        // The front controller calls exit here: neither $open nor the scope finish.
        Observability::shutdown();

        $exported = $client->spansById();
        self::assertArrayHasKey($serverId, $exported);
        self::assertArrayHasKey($open->context()->spanId(), $exported);
        self::assertSame($serverId, $exported[$open->context()->spanId()]['parentSpanId']);

        $server = self::attributes($exported[$serverId]);
        self::assertArrayHasKey('http.response.status_code', $server, 'SERVER span lost its HTTP status');
        self::assertSame('404', $server['http.response.status_code']);
        self::assertArrayNotHasKey('elven.span.incomplete', $server, 'the scope finalized the SERVER span, it is not incomplete');
        self::assertTrue(self::attributes($exported[$open->context()->spanId()])['elven.span.incomplete']);
    }

    public function testFatalErrorAtShutdownMarksTheOpenSpanAsError(): void
    {
        $autoload = realpath(__DIR__ . '/../../vendor/autoload.php');
        self::assertNotFalse($autoload);
        $script = tempnam(sys_get_temp_dir(), 'elven-fatal-');
        self::assertNotFalse($script);
        file_put_contents($script, self::fatalProbe((string) $autoload));

        $output = array();
        exec(
            escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -d log_errors=0 '
            . escapeshellarg($script) . ' 2>/dev/null',
            $output,
            $exitCode
        );
        unlink($script);

        self::assertSame(255, $exitCode, 'pre-condition: the probe dies of a fatal error');
        $spans = array();
        $rootId = null;
        foreach ($output as $line) {
            if (strpos($line, 'ROOT ') === 0) {
                $rootId = substr($line, 5);
            }
            if (strpos($line, 'EXPORT ') === 0) {
                $payload = json_decode(substr($line, 7), true);
                foreach ($payload['resourceSpans'][0]['scopeSpans'][0]['spans'] as $span) {
                    $spans[$span['spanId']] = $span;
                }
            }
        }

        self::assertNotNull($rootId);
        self::assertArrayHasKey($rootId, $spans, 'the open span of a process that died of a fatal must be exported');
        self::assertSame('STATUS_CODE_ERROR', $spans[$rootId]['status']['code']);
        $attributes = self::attributes($spans[$rootId]);
        self::assertTrue($attributes['elven.span.incomplete']);
        self::assertSame('process_shutdown', $attributes['elven.span.end_reason']);
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

    private static function replaceTraceClient($client)
    {
        $handle = Observability::init();
        $processor = self::getPrivate($handle, 'spanProcessor');
        $exporter = self::getPrivate($processor, 'exporter');
        self::assertInstanceOf(OtlpHttpJsonTraceExporter::class, $exporter);
        self::setPrivate($exporter, 'client', $client);
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

    private static function attributes(array $span)
    {
        $attributes = array();
        foreach ($span['attributes'] as $attribute) {
            $attributes[$attribute['key']] = current($attribute['value']);
        }
        return $attributes;
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

    private static function fatalProbe($autoload)
    {
        return '<?php
require ' . var_export($autoload, true) . ';

use Elven\Observability\PhpLegacy\Observability;
use Elven\Observability\PhpLegacy\Trace\Span;
use Elven\Observability\PhpLegacy\Trace\SpanContext;

$handle = Observability::init(array(
    "service_name" => "local-root-fatal-probe",
    "metrics_exporter" => "none",
    "logs_exporter" => "none",
));
$read = function ($object, $property) {
    $reflection = new ReflectionProperty(get_class($object), $property);
    $reflection->setAccessible(true);
    return array($reflection, $reflection->getValue($object));
};
list(, $processor) = $read($handle, "spanProcessor");
list(, $exporter) = $read($processor, "exporter");
list($clientProperty) = $read($exporter, "client");
$clientProperty->setValue($exporter, new class {
    public function post($url, array $payload)
    {
        echo "EXPORT " . json_encode($payload) . "\n";
        return true;
    }
});

$root = Observability::tracer()->startSpan("Message consume queue", array(
    "kind" => Span::KIND_CONSUMER,
    "parent_context" => new SpanContext(
        "' . self::REMOTE_TRACE_ID . '",
        "' . self::REMOTE_SPAN_ID . '",
        "01",
        "",
        true,
        true
    ),
));
echo "ROOT " . $root->context()->spanId() . "\n";
Observability::tracer()->startSpan("SELECT")->end();

elven_undefined_function_to_force_a_fatal_error();
';
    }
}
