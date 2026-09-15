<?php

namespace Elven\Observability\PhpLegacy\Tests\Unit;

use Elven\Observability\PhpLegacy\Instrumentation\GuzzleInstrumentation;
use Elven\Observability\PhpLegacy\Observability;
use Elven\Observability\PhpLegacy\Tests\Support\Env;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * Options of GuzzleInstrumentation::middleware(): the consumer's dependency name on the
 * span, and the 4xx policy of the CLIENT span.
 *
 * Measured in a consumer on 2026-09-09 (one trace, two GETs to the same internal
 * service, both 404 "no policy for this order"): the call made through
 * HttpClientInstrumentation carried the consumer's `dependency_name` and status UNSET,
 * the call made through this middleware carried the raw host, status ERROR and
 * `error.type=404`. The consumer had passed its dependency name to its own client
 * factory; the middleware had no way to receive it, and no way to leave a legitimate
 * 4xx out of `status = error`.
 *
 * The default is NOT changed here: a CLIENT 4xx still marks the span ERROR, as HTTP
 * semconv recommends for CLIENT spans and as
 * AdvancedInstrumentationTest::testGuzzleMiddlewareSupportsPsr7AndMarksClientFourHundredAsError
 * pins. The policy is opt-in.
 */
final class GuzzleClientErrorPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        Env::reset();
        putenv('ELVEN_OTEL_ENABLED=true');
        putenv('OTEL_TRACES_EXPORTER=none');
        putenv('OTEL_LOGS_EXPORTER=none');
        Observability::init(array('service_name' => 'guzzle-client-error-policy-test'));
    }

    public function testOptedOutClientErrorLeavesTheSpanUnsetWithoutErrorType(): void
    {
        $span = null;
        $client = $this->client(function () use (&$span) {
            $span = Observability::tracer()->currentSpan();
            return new FulfilledPromise(new Response(404));
        }, array('mark_client_errors' => false));

        $response = $client->request('GET', 'https://insurance.internal.test/api/policies/order/77', array(
            'http_errors' => false,
        ));

        self::assertSame(404, $response->getStatusCode());
        self::assertNotNull($span);
        self::assertTrue($span->isEnded());
        self::assertSame('UNSET', $span->statusCode());
        self::assertArrayNotHasKey('error.type', $span->attributes());
        self::assertSame(404, $span->attributes()['http.response.status_code']);
    }

    public function testDependencyNameOptionNamesTheSpanAndKeepsTheHostWhereItWas(): void
    {
        $span = null;
        $client = $this->client(function () use (&$span) {
            $span = Observability::tracer()->currentSpan();
            return new FulfilledPromise(new Response(200));
        }, array('dependency_name' => 'ms-insurance'));

        $client->request('GET', 'https://insurance.internal.test/api/policies/order/77');

        self::assertSame('ms-insurance', $span->attributes()['dependency_name']);
        self::assertSame('insurance.internal.test', $span->attributes()['server.address']);
        self::assertSame('HTTP GET insurance.internal.test', $span->name());
    }

    public function testBlankDependencyNameFallsBackToTheHost(): void
    {
        $span = null;
        $client = $this->client(function () use (&$span) {
            $span = Observability::tracer()->currentSpan();
            return new FulfilledPromise(new Response(200));
        }, array('dependency_name' => '   '));

        $client->request('GET', 'https://insurance.internal.test/api/policies/order/77');

        self::assertSame('insurance.internal.test', $span->attributes()['dependency_name']);
    }

    public function testServerErrorIsStillAnErrorWhenClientErrorsAreNotMarked(): void
    {
        $span = null;
        $client = $this->client(function () use (&$span) {
            $span = Observability::tracer()->currentSpan();
            return new FulfilledPromise(new Response(503));
        }, array('mark_client_errors' => false));

        $client->request('GET', 'https://insurance.internal.test/api/insure/quotation', array(
            'http_errors' => false,
        ));

        self::assertSame('ERROR', $span->statusCode());
        self::assertSame('503', $span->attributes()['error.type']);
    }

    public function testDefaultStillMarksAClientErrorAsError(): void
    {
        $span = null;
        $client = $this->client(function () use (&$span) {
            $span = Observability::tracer()->currentSpan();
            return new FulfilledPromise(new Response(404));
        }, null);

        $client->request('GET', 'https://insurance.internal.test/api/policies/order/77', array(
            'http_errors' => false,
        ));

        self::assertSame('ERROR', $span->statusCode());
        self::assertSame('404', $span->attributes()['error.type']);
        self::assertSame('insurance.internal.test', $span->attributes()['dependency_name']);
    }

    public function testOptedOutRejectedClientErrorIsNotAnErrorAndStillRejectsWithTheSameObject(): void
    {
        $span = null;
        $rejection = null;
        $client = $this->client(function ($request) use (&$span, &$rejection) {
            $span = Observability::tracer()->currentSpan();
            $rejection = new ClientException('Client error: 404', $request, new Response(404));
            return new RejectedPromise($rejection);
        }, array('mark_client_errors' => false));

        $caught = null;
        try {
            $client->request('GET', 'https://insurance.internal.test/api/policies/order/77');
        } catch (\Throwable $e) {
            $caught = $e;
        }

        self::assertSame($rejection, $caught);
        self::assertTrue($span->isEnded());
        self::assertSame('UNSET', $span->statusCode());
        self::assertArrayNotHasKey('error.type', $span->attributes());
        self::assertSame(404, $span->attributes()['http.response.status_code']);
    }

    public function testOptedOutRejectedServerErrorIsStillAnError(): void
    {
        $span = null;
        $client = $this->client(function ($request) use (&$span) {
            $span = Observability::tracer()->currentSpan();
            return new RejectedPromise(new ServerException('Server error: 502', $request, new Response(502)));
        }, array('mark_client_errors' => false));

        try {
            $client->request('GET', 'https://insurance.internal.test/api/insure/quotation');
            self::fail('the rejection must reach the caller');
        } catch (ServerException $expected) {
        }

        self::assertSame('ERROR', $span->statusCode());
        self::assertSame(ServerException::class, $span->attributes()['error.type']);
    }

    public function testOptedOutTransportFailureIsStillAnError(): void
    {
        $span = null;
        $client = $this->client(function ($request) use (&$span) {
            $span = Observability::tracer()->currentSpan();
            return new RejectedPromise(new ConnectException('Connection refused', $request));
        }, array('mark_client_errors' => false));

        try {
            $client->request('GET', 'https://insurance.internal.test/api/insure/quotation');
            self::fail('the rejection must reach the caller');
        } catch (ConnectException $expected) {
        }

        self::assertSame('ERROR', $span->statusCode());
        self::assertSame(ConnectException::class, $span->attributes()['error.type']);
    }

    /**
     * `mark_client_errors` often comes from the consumer's config or environment, where a
     * boolean arrives as `0`, `'0'`, `'false'` or `'no'`. Accepting only the boolean `false`
     * would keep those consumers on ERROR without any sign that the setting was ignored.
     *
     * @dataProvider disablingValues
     * @param mixed $value
     */
    public function testFalsyConfigValuesTurnClientErrorMarkingOff($value): void
    {
        $span = $this->spanOf404(array('mark_client_errors' => $value));

        self::assertSame('UNSET', $span->statusCode());
        self::assertArrayNotHasKey('error.type', $span->attributes());
        self::assertSame(404, $span->attributes()['http.response.status_code']);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public function disablingValues(): array
    {
        return array(
            'bool false' => array(false),
            'int 0' => array(0),
            'string 0' => array('0'),
            'string false' => array('false'),
            'string FALSE' => array('FALSE'),
            'string no' => array('no'),
            'string off' => array('off'),
            'string no with spaces' => array(' no '),
        );
    }

    /**
     * The other side of the parsing: anything that is not a recognisable "false" keeps the
     * default, so a typo or an empty environment variable never silences 4xx errors.
     *
     * @dataProvider defaultKeepingValues
     * @param mixed $value
     */
    public function testValuesThatAreNotARecognisableFalseKeepTheDefault($value): void
    {
        $span = $this->spanOf404(array('mark_client_errors' => $value));

        self::assertSame('ERROR', $span->statusCode());
        self::assertSame('404', $span->attributes()['error.type']);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public function defaultKeepingValues(): array
    {
        return array(
            'bool true' => array(true),
            'int 1' => array(1),
            'string 1' => array('1'),
            'string true' => array('true'),
            'string yes' => array('yes'),
            'null' => array(null),
            'empty string' => array(''),
            'blank string' => array('   '),
            'unrecognised word' => array('nao'),
            'int 2' => array(2),
            'array' => array(array()),
        );
    }

    /**
     * @return \Elven\Observability\PhpLegacy\Trace\Span
     */
    private function spanOf404(array $options)
    {
        $span = null;
        $client = $this->client(function () use (&$span) {
            $span = Observability::tracer()->currentSpan();
            return new FulfilledPromise(new Response(404));
        }, $options);

        $client->request('GET', 'https://insurance.internal.test/api/policies/order/77', array(
            'http_errors' => false,
        ));

        self::assertNotNull($span);
        self::assertTrue($span->isEnded());

        return $span;
    }

    /**
     * @param array|null $options null = middleware() called without arguments
     */
    private function client(callable $handler, $options): Client
    {
        $stack = new HandlerStack($handler);
        $stack->push($options === null ? GuzzleInstrumentation::middleware() : GuzzleInstrumentation::middleware($options));

        return new Client(array('handler' => $stack));
    }
}
