<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Aws\Sns\SnsClient;
use Elven\Observability\PhpLegacy\Instrumentation\AwsInstrumentation;
use Elven\Observability\PhpLegacy\Observability;

Observability::init(array('service_name' => 'legacy-public-api'));

$client = new SnsClient(array(
    'version' => '2010-03-31',
    'region' => getenv('AWS_REGION') ?: 'us-east-1',
    // Use the SDK default credential provider chain. Never hard-code keys.
));
AwsInstrumentation::register($client, 'sns');

// Existing calls now create CLIENT spans and propagate traceparent:
// $client->publish(array('TopicArn' => $topicArn, 'Message' => $safeMessage));
