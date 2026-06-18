<?php

// Focused micro-benchmark for the attribute redaction hot path.
// The main run.php benchmark is dominated by user CPU (sha1 loop), so it is not
// sensitive to redaction cost. This isolates redactAttributes() over a realistic
// per-request attribute set (the kind emitted by the Zupper legacy instrumentation).

require_once __DIR__ . '/../vendor/autoload.php';

use Elven\Observability\PhpLegacy\Config\EnvConfigResolver;
use Elven\Observability\PhpLegacy\Privacy\AttributeRedactor;

$resolved = EnvConfigResolver::resolve(array('service_name' => 'bench'));
$redactor = new AttributeRedactor($resolved);

$attrs = array(
    'messaging.system' => 'rabbitmq',
    'messaging.operation' => 'process',
    'messaging.destination.name' => 'zupper.aerial.search',
    'operation' => 'session.login',
    'zupper.rest.version' => 'v2',
    'auth.outcome' => 'granted',
    'http.response.status_code' => 200,
    'dependency_name' => 'mysql',
    'dependency_type' => 'sql',
    'traffic_source' => 'skyscanner',
    'traffic_channel' => 'metasearch',
    'aws.s3.bucket' => 'zupper-assets',
    'db.system' => 'mysql',
    'http.route' => '/rest/v2/aerial/search',
);

$iterations = 200000;

// warmup
for ($i = 0; $i < 1000; $i++) {
    $redactor->redactAttributes($attrs);
}

$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $redactor->redactAttributes($attrs);
}
$elapsed = (microtime(true) - $start);

$perCall = ($elapsed / $iterations) * 1e6; // microseconds per redactAttributes() call
$perAttr = $perCall / count($attrs);
printf("redactAttributes: %d iters over %d attrs in %.3fs\n", $iterations, count($attrs), $elapsed);
printf("  per call: %.3f us\n", $perCall);
printf("  per attribute: %.3f us\n", $perAttr);
