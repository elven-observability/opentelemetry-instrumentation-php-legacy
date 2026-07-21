<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Elven\Observability\PhpLegacy\Instrumentation\CurlInstrumentation;
use Elven\Observability\PhpLegacy\Observability;

Observability::init(array('service_name' => 'curl-example'));

$url = 'https://example.com/health';

$result = CurlInstrumentation::instrument('GET', $url, function ($span, array $headers) use ($url) {
    $headers = CurlInstrumentation::headersForCurl(array_merge(array('Accept' => 'application/json'), $headers), $span);
    $curl = curl_init($url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
    $body = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    return array('status_code' => $status, 'body' => $body);
});

Observability::shutdown();
echo 'status=' . (int) $result['status_code'] . PHP_EOL;
echo 'response_bytes=' . strlen((string) $result['body']) . PHP_EOL;
