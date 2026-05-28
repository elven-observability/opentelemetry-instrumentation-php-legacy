<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Elven\Observability\PhpLegacy\Instrumentation\GuzzleInstrumentation;
use Elven\Observability\PhpLegacy\Observability;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;

Observability::init(array('service_name' => 'guzzle-example'));

$stack = HandlerStack::create();
$stack->push(GuzzleInstrumentation::middleware());

$client = new Client(array('handler' => $stack));
$response = $client->get('https://example.com/health');

Observability::shutdown();
echo $response->getStatusCode() . PHP_EOL;
