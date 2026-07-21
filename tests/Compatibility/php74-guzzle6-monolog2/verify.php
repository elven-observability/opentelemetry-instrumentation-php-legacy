<?php

require __DIR__ . '/../../../var/compat-php74-guzzle6-monolog2-vendor/autoload.php';

use Elven\Observability\PhpLegacy\Instrumentation\GuzzleInstrumentation;
use Elven\Observability\PhpLegacy\Logs\MonologOtlpHandler;
use Elven\Observability\PhpLegacy\Logs\MonologTraceProcessor;
use Elven\Observability\PhpLegacy\Observability;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Response;

putenv('ELVEN_OTEL_ENABLED=true');
putenv('OTEL_TRACES_EXPORTER=none');
putenv('OTEL_METRICS_EXPORTER=none');
putenv('OTEL_LOGS_EXPORTER=none');

Observability::init(array('service_name' => 'php74-compatibility-fixture'));

$capturedGuzzleRequest = null;
$stack = new HandlerStack(function ($request) use (&$capturedGuzzleRequest) {
    $capturedGuzzleRequest = $request;
    return new FulfilledPromise(new Response(204));
});
$stack->push(GuzzleInstrumentation::middleware(), 'elven.otel');
$guzzle = new Client(array('handler' => $stack));
$response = $guzzle->request('POST', 'https://dependency.example.test/events');
selfAssert($response->getStatusCode() === 204, 'Guzzle 6 response was changed');
selfAssert(
    preg_match('/^00-[a-f0-9]{32}-[a-f0-9]{16}-01$/', $capturedGuzzleRequest->getHeaderLine('traceparent')) === 1,
    'Guzzle 6 traceparent was not injected'
);

$processor = new MonologTraceProcessor();
$record = $processor(array(
    'message' => 'compatibility-check',
    'context' => array(),
    'level' => 200,
    'level_name' => 'INFO',
    'channel' => 'compatibility',
    'datetime' => new DateTimeImmutable(),
    'extra' => array(),
));
selfAssert(isset($record['extra']['service_name']), 'Monolog 2 processor correlation failed');
$handler = new MonologOtlpHandler();
selfAssert($handler instanceof MonologOtlpHandler, 'Monolog 2 OTLP handler could not be constructed');

Observability::shutdown();
fwrite(STDOUT, "PHP 7.4, Guzzle 6, and Monolog 2 compatibility passed.\n");

function selfAssert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}
