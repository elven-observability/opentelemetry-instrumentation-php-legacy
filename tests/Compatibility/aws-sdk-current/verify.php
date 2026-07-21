<?php

require __DIR__ . '/../../../vendor/autoload.php';

use Aws\Credentials\Credentials;
use Aws\Sns\SnsClient;
use Elven\Observability\PhpLegacy\Instrumentation\AwsInstrumentation;
use Elven\Observability\PhpLegacy\Observability;

putenv('ELVEN_OTEL_ENABLED=true');
putenv('OTEL_TRACES_EXPORTER=none');
putenv('OTEL_METRICS_EXPORTER=none');
putenv('OTEL_LOGS_EXPORTER=none');

Observability::init(array('service_name' => 'aws-sdk-current-fixture'));

$sns = new SnsClient(array(
    'version' => '2010-03-31',
    'region' => 'us-east-1',
    'credentials' => new Credentials('fixture-access-key', 'fixture-secret-key'),
));
AwsInstrumentation::register($sns, 'sns');
$handlerCount = count($sns->getHandlerList());
AwsInstrumentation::register($sns, 'sns');
selfAssert(count($sns->getHandlerList()) === $handlerCount, 'AWS middleware registration is not idempotent');

$command = $sns->getCommand('Publish', array(
    'Message' => 'compatibility-check',
    'TopicArn' => 'arn:aws:sns:us-east-1:000000000000:compatibility-check',
));
$request = Aws\serialize($command);
selfAssert(
    preg_match('/^00-[a-f0-9]{32}-[a-f0-9]{16}-01$/', $request->getHeaderLine('traceparent')) === 1,
    'AWS SDK traceparent was not injected'
);
selfAssert(
    stripos($request->getHeaderLine('Authorization'), 'traceparent') !== false,
    'AWS SDK traceparent was injected after SigV4 signing'
);

Observability::shutdown();
fwrite(STDOUT, "Current AWS SDK middleware compatibility passed.\n");

function selfAssert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}
