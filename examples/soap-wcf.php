<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Elven\Observability\PhpLegacy\Instrumentation\SoapInstrumentation;
use Elven\Observability\PhpLegacy\Observability;

Observability::init(array('service_name' => 'soap-example'));

$headers = array(
    'Content-type: application/soap+xml;charset="utf-8"',
    'Accept: text/xml',
    'SOAPAction: Search',
);
$result = SoapInstrumentation::instrument('DSG', 'Search', 'dsg.internal', 12, function ($span) use ($headers) {
    $headers = SoapInstrumentation::injectHttpHeaders($headers, $span);
    return array('headers' => $headers, 'success' => true);
});

Observability::shutdown();
print_r($result);
