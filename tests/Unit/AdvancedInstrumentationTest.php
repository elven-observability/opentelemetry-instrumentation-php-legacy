<?php

namespace Elven\Observability\PhpLegacy\Tests\Unit;

use Elven\Observability\PhpLegacy\Instrumentation\AwsInstrumentation;
use Elven\Observability\PhpLegacy\Instrumentation\CliInstrumentation;
use Elven\Observability\PhpLegacy\Instrumentation\DbInstrumentation;
use Elven\Observability\PhpLegacy\Instrumentation\GuzzleInstrumentation;
use Elven\Observability\PhpLegacy\Observability;
use Elven\Observability\PhpLegacy\Tests\Support\Env;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class AdvancedInstrumentationTest extends TestCase
{
    protected function setUp(): void
    {
        Env::reset();
        putenv('ELVEN_OTEL_ENABLED=true');
        putenv('OTEL_TRACES_EXPORTER=none');
        putenv('OTEL_LOGS_EXPORTER=none');
        Observability::init(array('service_name' => 'advanced-instrumentation-test'));
    }

    public function testGuzzleMiddlewareSupportsPsr7AndMarksClientFourHundredAsError(): void
    {
        $capturedRequest = null;
        $capturedSpan = null;
        $handler = function ($request) use (&$capturedRequest, &$capturedSpan) {
            $capturedRequest = $request;
            $capturedSpan = Observability::tracer()->currentSpan();
            return new FulfilledPromise(new Response(404));
        };
        $stack = new HandlerStack($handler);
        $stack->push(GuzzleInstrumentation::middleware());
        $client = new Client(array('handler' => $stack));

        $response = $client->request('GET', 'https://dependency.test/customer/12345', array(
            'http_errors' => false,
        ));

        self::assertSame(404, $response->getStatusCode());
        self::assertMatchesRegularExpression(
            '/^00-[a-f0-9]{32}-[a-f0-9]{16}-01$/',
            $capturedRequest->getHeaderLine('traceparent')
        );
        self::assertSame('ERROR', $capturedSpan->statusCode());
        self::assertSame('404', $capturedSpan->attributes()['error.type']);
        self::assertTrue($capturedSpan->isEnded());
        self::assertSame('HTTP GET dependency.test', $capturedSpan->name());
    }

    public function testGuzzleMiddlewarePreservesSynchronousApplicationException(): void
    {
        $original = new \RuntimeException('network failed');
        $stack = new HandlerStack(function () use ($original) {
            throw $original;
        });
        $stack->push(GuzzleInstrumentation::middleware());
        $client = new Client(array('handler' => $stack));

        $caught = null;
        try {
            $client->request('GET', 'https://dependency.test/fail');
        } catch (\Throwable $e) {
            $caught = $e;
        }
        self::assertSame($original, $caught);
    }

    public function testGuzzleMiddlewareKeepsRejectedPromiseRejected(): void
    {
        $stack = new HandlerStack(function () {
            return new RejectedPromise('transport-rejected');
        });
        $stack->push(GuzzleInstrumentation::middleware());
        $client = new Client(array('handler' => $stack));

        $caught = null;
        try {
            $client->request('GET', 'https://dependency.test/rejected');
        } catch (\Throwable $e) {
            $caught = $e;
        }
        self::assertNotNull($caught);
        self::assertStringContainsString('transport-rejected', $caught->getMessage());
    }

    public function testPendingGuzzlePromiseDoesNotRemainActiveOrParentConcurrentRequests(): void
    {
        $promises = array(new Promise(), new Promise());
        $capturedSpans = array();
        $next = 0;
        $handler = function () use (&$next, &$capturedSpans, $promises) {
            $capturedSpans[] = Observability::tracer()->currentSpan();
            return $promises[$next++];
        };
        $wrapped = GuzzleInstrumentation::middleware()($handler);
        $root = Observability::tracer()->startSpan('request-root');

        $first = $wrapped(new Request('GET', 'https://one.test/path'), array());
        self::assertSame($root, Observability::tracer()->currentSpan());
        $second = $wrapped(new Request('GET', 'https://two.test/path'), array());
        self::assertSame($root, Observability::tracer()->currentSpan());
        self::assertSame($root->context()->spanId(), $capturedSpans[0]->parentContext()->spanId());
        self::assertSame($root->context()->spanId(), $capturedSpans[1]->parentContext()->spanId());

        $promises[1]->resolve(new Response(200));
        $promises[0]->resolve(new Response(200));
        self::assertSame(200, $second->wait()->getStatusCode());
        self::assertSame(200, $first->wait()->getStatusCode());
        $root->end();
    }

    public function testAwsMiddlewareInjectsBeforeHandlerAndKeepsResult(): void
    {
        $capturedRequest = null;
        $capturedSpan = null;
        $handler = function ($command, $request) use (&$capturedRequest, &$capturedSpan) {
            $capturedRequest = $request;
            $capturedSpan = Observability::tracer()->currentSpan();
            return new FulfilledPromise(array('@metadata' => array('statusCode' => 200), 'MessageId' => 'ignored'));
        };
        $middleware = AwsInstrumentation::middleware('sns');
        $wrapped = $middleware($handler);
        $command = new class {
            public function getName()
            {
                return 'Publish';
            }
        };

        $result = $wrapped($command, new Request('POST', 'https://sns.sa-east-1.amazonaws.com'))->wait();

        self::assertSame(200, $result['@metadata']['statusCode']);
        self::assertNotSame('', $capturedRequest->getHeaderLine('traceparent'));
        self::assertSame('AWS sns publish', $capturedSpan->name());
        self::assertTrue($capturedSpan->isEnded());
        self::assertArrayNotHasKey('MessageId', $capturedSpan->attributes());
    }

    public function testDatabaseInstrumentationEmitsStableAndLegacyAttributesWithoutRawByDefault(): void
    {
        $captured = null;
        $result = DbInstrumentation::traceQuery(
            'mysql',
            'select',
            'tenant-db',
            function ($span) use (&$captured) {
                $captured = $span;
                return 'database-result';
            },
            "SELECT * FROM customers WHERE email = 'private@example.test'"
        );

        self::assertSame('database-result', $result);
        self::assertSame('mysql', $captured->attributes()['db.system.name']);
        self::assertSame('tenant-db', $captured->attributes()['db.namespace']);
        self::assertSame('SELECT customers', $captured->attributes()['db.query.summary']);
        self::assertArrayNotHasKey('db.query.text', $captured->attributes());
        self::assertArrayNotHasKey('db.statement', $captured->attributes());
    }

    public function testDatabaseInstrumentationExportsOnlySanitizedTextWhenConfigured(): void
    {
        Env::reset();
        putenv('ELVEN_OTEL_ENABLED=true');
        putenv('OTEL_TRACES_EXPORTER=none');
        putenv('OTEL_LOGS_EXPORTER=none');
        putenv('ELVEN_OTEL_CAPTURE_DB_STATEMENT=true');
        putenv('ELVEN_OTEL_REDACT_DB_STATEMENT=true');
        Observability::init(array('service_name' => 'sanitized-db-test'));

        $captured = null;
        DbInstrumentation::traceQuery(
            'mysql',
            'select',
            'application',
            function ($span) use (&$captured) {
                $captured = $span;
                return true;
            },
            "SELECT * FROM customers WHERE email='private@example.test' AND id=938471"
        );

        self::assertSame(
            'SELECT * FROM customers WHERE email=? AND id=?',
            $captured->attributes()['db.query.text']
        );
        self::assertSame($captured->attributes()['db.query.text'], $captured->attributes()['db.statement']);
        self::assertStringNotContainsString('private@example.test', $captured->attributes()['db.query.text']);
        self::assertStringNotContainsString('938471', $captured->attributes()['db.query.text']);
    }

    public function testCliInstrumentationPreservesResultAndRecordsBoundedMetrics(): void
    {
        $result = CliInstrumentation::run('send-email', function ($span) {
            self::assertSame('job send-email', $span->name());
            return 42;
        });
        self::assertSame(42, $result);

        $found = false;
        foreach (Observability::metrics()->collect() as $metric) {
            if ($metric['name'] === 'elven.php.job.duration') {
                $found = true;
                self::assertSame('send-email', $metric['points'][0]['attributes']['operation']);
                self::assertSame('success', $metric['points'][0]['attributes']['result']);
            }
        }
        self::assertTrue($found);
    }

    public function testSpanLimitsBoundAttributesEventsAndValueSize(): void
    {
        Env::reset();
        putenv('ELVEN_OTEL_ENABLED=true');
        putenv('OTEL_TRACES_EXPORTER=none');
        putenv('OTEL_LOGS_EXPORTER=none');
        putenv('ELVEN_OTEL_MAX_ATTRIBUTES_PER_SPAN=2');
        putenv('ELVEN_OTEL_MAX_ATTRIBUTE_LENGTH=32');
        putenv('ELVEN_OTEL_MAX_EVENTS_PER_SPAN=1');
        putenv('ELVEN_OTEL_MAX_EVENT_ATTRIBUTES=1');
        Observability::init(array('service_name' => 'limits-test'));

        $span = Observability::tracer()->startSpan('bounded');
        $span->setAttribute('one', str_repeat('x', 1000));
        $span->setAttribute('two', 'ok');
        $span->setAttribute('three', 'dropped');
        $span->addEvent('first', array('one' => '1', 'two' => '2'));
        $span->addEvent('second');

        self::assertCount(2, $span->attributes());
        self::assertLessThanOrEqual(32, strlen($span->attributes()['one']));
        self::assertSame(1, $span->droppedAttributesCount());
        self::assertCount(1, $span->events());
        self::assertSame(1, $span->events()[0]['droppedAttributesCount']);
        self::assertSame(1, $span->droppedEventsCount());
        $span->end();
    }

    public function testSpanArrayAttributeHasOneTotalByteBudget(): void
    {
        Env::reset();
        putenv('ELVEN_OTEL_ENABLED=true');
        putenv('OTEL_TRACES_EXPORTER=none');
        putenv('OTEL_LOGS_EXPORTER=none');
        putenv('ELVEN_OTEL_MAX_ATTRIBUTE_LENGTH=64');
        Observability::init(array('service_name' => 'array-limit-test'));

        $span = Observability::tracer()->startSpan('bounded-array');
        $span->setAttribute('items', array_fill(0, 32, str_repeat('x', 64)));
        $bytes = 0;
        foreach ($span->attributes()['items'] as $item) {
            $bytes += strlen((string) $item);
        }

        self::assertLessThanOrEqual(64, $bytes);
        $span->end();
    }

    public function testSpanNameAndStatusMessageAreBounded(): void
    {
        $span = Observability::tracer()->startSpan(str_repeat('n', 10000));
        $span->setStatus('ERROR', str_repeat('m', 10000));

        self::assertLessThanOrEqual(255, strlen($span->name()));
        self::assertLessThanOrEqual(4096, strlen($span->statusMessage()));
        $span->end();
    }
}
