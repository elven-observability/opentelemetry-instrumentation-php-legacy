<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Elven\Observability\PhpLegacy\Bridge\Legacy\FrontControllerInstrumentation;
use Elven\Observability\PhpLegacy\Observability;

Observability::init(array(
    'service_name' => 'legacy-public-api',
    'service_namespace' => 'customer-platform',
));

$routes = array(
    'Customer' => array(
        'controller' => 'CustomerController',
        'submodules' => array('history' => 'CustomerHistoryController'),
    ),
    'Healthz' => array(
        'controller' => 'HealthController',
        'submodules' => array('live' => 'HealthLiveController'),
    ),
);

$scope = FrontControllerInstrumentation::beginFromGlobals($routes);
$throwable = null;
try {
    // Replace with the application's existing router/controller dispatch.
    $result = array('ok' => true);
} catch (\Throwable $error) {
    $throwable = $error;
    $scope->recordException($error);
    throw $error;
} finally {
    // Idempotent. If legacy response code calls exit/die, the library's own
    // shutdown registry closes this scope before exporters flush.
    $scope->finish($throwable);
}

header('Content-Type: application/json');
echo json_encode($result);
