<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Elven\Observability\PhpLegacy\Bridge\Legacy\RestRouteInstrumentation;
use Elven\Observability\PhpLegacy\Observability;

Observability::init(array(
    'service_name' => 'legacy-booking-api',
    'service_namespace' => 'booking',
    'environment' => 'staging',
));

$result = RestRouteInstrumentation::traceRestAction('1', 'ticket', 'search', function ($span) {
    $span->setAttribute('operation', 'ticket_search');
    return array('ok' => true);
});

Observability::shutdown();

var_dump($result);
