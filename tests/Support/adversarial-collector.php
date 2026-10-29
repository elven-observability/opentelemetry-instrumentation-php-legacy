<?php

$argv = isset($_SERVER['argv']) && is_array($_SERVER['argv']) ? $_SERVER['argv'] : array();
if (count($argv) < 4) {
    exit(2);
}
$port = (int) $argv[1];
$mode = (string) $argv[2];
$store = (string) $argv[3];
$certificate = isset($argv[4]) ? $argv[4] : '';
$context = stream_context_create(array('ssl' => array('local_cert' => $certificate)));
$server = stream_socket_server(
    ($mode === 'tls' ? 'tls' : 'tcp') . '://127.0.0.1:' . $port,
    $errno,
    $error,
    STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
    $context
);
if (!$server) {
    exit(2);
}
file_put_contents($store . '.ready', 'ready');
while ($socket = @stream_socket_accept($server, 10)) {
    stream_set_timeout($socket, 1);
    $request = '';
    while (strpos($request, "\r\n\r\n") === false && strlen($request) < 32768 && !feof($socket)) {
        $request .= fread($socket, 1);
    }
    if (preg_match('/Content-Length: ([0-9]+)/i', $request, $matches)) {
        $remaining = min(4194304, (int) $matches[1]);
        while ($remaining > 0 && !feof($socket)) {
            $chunk = (string) fread($socket, min(8192, $remaining));
            if ($chunk === '') {
                break;
            }
            $remaining -= strlen($chunk);
        }
    }
    file_put_contents($store, "request\n", FILE_APPEND);
    file_put_contents($store . '.headers', $request, FILE_APPEND);
    if ($mode === 'stall') {
        usleep(2000000);
    } elseif ($mode === 'drip-header' || $mode === 'drip-body') {
        @fwrite($socket, $mode === 'drip-header' ? "HTTP/1.1 200 OK\r\nX-Drip: "
            : "HTTP/1.1 200 OK\r\nContent-Length: 200\r\n\r\n");
        for ($i = 0; $i < 200; $i++) {
            if (!@fwrite($socket, 'x')) {
                break;
            }
            usleep(10000);
        }
    } elseif ($mode === 'large-header') {
        @fwrite($socket, "HTTP/1.1 200 OK\r\n");
        for ($i = 0; $i < 8; $i++) {
            @fwrite($socket, 'X-Pad-' . $i . ': ' . str_repeat('x', 8192) . "\r\n");
        }
        @fwrite($socket, "Content-Length: 2\r\n\r\n{}");
    } elseif ($mode === 'large-body' || $mode === 'large-close-body' || $mode === 'chunked') {
        $framing = $mode === 'large-body' ? "Content-Length: 1048576"
            : ($mode === 'chunked' ? "Transfer-Encoding: chunked" : "Connection: close");
        @fwrite($socket, "HTTP/1.1 200 OK\r\n" . $framing . "\r\n\r\n"
            . ($mode === 'chunked' ? "100000\r\n" : '') . str_repeat('x', 1048576));
    } elseif ($mode === 'duplicates') {
        @fwrite($socket, "HTTP/1.1 200 OK\r\nSet-Cookie: a=1\r\nSet-Cookie: b=2\r\nContent-Length: 2\r\n\r\n{}");
    } elseif ($mode === 'duplicate-framing') {
        @fwrite($socket, "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nContent-Length: 8\r\n\r\n{}");
    } elseif ($mode === 'healthy-chunked') {
        @fwrite($socket, "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n2\r\n{}\r\n0\r\n\r\n");
    } elseif ($mode === 'retry-long' || $mode === 'retry-zero') {
        @fwrite($socket, "HTTP/1.1 503 Unavailable\r\nRetry-After: "
            . ($mode === 'retry-long' ? '3600' : '0') . "\r\nContent-Length: 2\r\n\r\n{}");
    } else {
        @fwrite($socket, "HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\n{}");
    }
    fclose($socket);
}
