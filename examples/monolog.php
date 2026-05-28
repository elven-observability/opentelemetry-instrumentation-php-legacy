<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Elven\Observability\PhpLegacy\Logs\MonologTraceProcessor;
use Elven\Observability\PhpLegacy\Logs\MonologOtlpHandler;
use Elven\Observability\PhpLegacy\Observability;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

Observability::init(array('service_name' => 'monolog-example'));

$logger = new Logger('example');
$logger->pushProcessor(new MonologTraceProcessor());
$logger->pushHandler(new StreamHandler('php://stdout'));
$logger->pushHandler(new MonologOtlpHandler());

Observability::tracer()->withSpan('log-example', function () use ($logger) {
    $logger->info('safe event', array('operation' => 'demo', 'token' => 'redacted-by-default'));
});

Observability::shutdown();
