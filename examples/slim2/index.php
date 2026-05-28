<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use Elven\Observability\PhpLegacy\Instrumentation\Slim2Instrumentation;
use Elven\Observability\PhpLegacy\Observability;

Observability::init(array(
    'service_name' => 'legacy-slim2-example',
    'service_namespace' => 'examples',
    'environment' => 'local',
));

$result = Slim2Instrumentation::instrumentRestRoute('v1', 'ticket', 'search', function ($span) {
    $span->setAttribute('operation', 'ticket_search');
    Observability::metrics()->counter('booking.ticket.search.started')->add(1, array(
        'operation' => 'ticket_search',
        'route' => '/rest/v1/ticket/search',
    ));
    return array('success' => true);
});

header('Content-Type: application/json');
echo json_encode($result);
Observability::shutdown();
