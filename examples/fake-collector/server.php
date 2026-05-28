<?php

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';

if ($method !== 'POST' || !in_array($path, array('/v1/traces', '/v1/metrics', '/v1/logs'), true)) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(array('error' => 'not_found'));
    return;
}

$body = file_get_contents('php://input');
$decoded = json_decode($body, true);
$dir = __DIR__ . '/../../var/fake-collector';
if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
}

$event = array(
    'path' => $path,
    'content_type' => isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '',
    'body' => $decoded,
    'raw_length' => strlen($body),
    'received_at' => gmdate('c'),
);

file_put_contents($dir . '/events.jsonl', json_encode($event) . "\n", FILE_APPEND);
http_response_code(200);
header('Content-Type: application/json');
echo json_encode(array('partialSuccess' => array()));
