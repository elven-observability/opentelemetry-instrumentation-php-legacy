<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Elven\Observability\PhpLegacy\Attribution\TrafficSourceResolver;
use Elven\Observability\PhpLegacy\Observability;

$handle = Observability::init(array('service_name' => 'traffic-attribution-example'));

$request = array(
    'utmSource' => 'skyscanner',
    'utmMedium' => 'metasearch',
);

Observability::tracer()->withSpan('GET /rest/v1/ticket/search', function ($span) use ($request) {
    $traffic = TrafficSourceResolver::attributesFromRequest($request, $_SERVER);
    Observability::metrics()->setRequestAttributes($traffic);
    $span->setAttributes($traffic);

    Observability::metrics()->counter('booking.ticket.search.started')->add(1, array(
        'operation' => 'ticket_search',
    ));
});

$handle->shutdown();
