<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Elven\Observability\PhpLegacy\Instrumentation\CliInstrumentation;
use Elven\Observability\PhpLegacy\Observability;

Observability::init(array('service_name' => 'legacy-worker'));

$exitCode = CliInstrumentation::run('send-notifications', function ($span) {
    $span->setAttribute('job.batch.type', 'scheduled');
    // Run the existing job body. Do not attach messages or recipients.
    return 0;
}, array(), true);

exit($exitCode);
