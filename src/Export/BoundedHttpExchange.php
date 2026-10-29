<?php

namespace Elven\Observability\PhpLegacy\Export;

use RuntimeException;

/** One non-redirecting HTTP exchange, with bounded input, response and elapsed time. */
final class BoundedHttpExchange
{
    /**
     * @param array<string, string> $headers
     * @return array{ok: bool, status: int, headers: array<string, string>, body: string}
     */
    public function post(string $url, string $payload, array $headers, int $timeoutMillis, bool $stream = false): array
    {
        $parts = parse_url($url);
        if (
            !is_array($parts) || !isset($parts['host'], $parts['scheme'])
            || !in_array(strtolower($parts['scheme']), array('http', 'https'), true)
            || isset($parts['user']) || isset($parts['pass']) || strlen($url) > 8192
            || strlen($payload) > ExportBudget::WIRE_BYTES || $timeoutMillis < 1
        ) {
            throw new RuntimeException('Invalid or oversized OTLP request');
        }
        $lines = array('Accept-Encoding: identity', 'Connection: close', 'Expect:');
        $requestHeaderBytes = 64;
        foreach ($headers as $name => $value) {
            $requestHeaderBytes += strlen($name) + strlen($value) + 4;
            if (strlen($name) > 255 || $requestHeaderBytes > ExportBudget::HEADER_BYTES || count($lines) >= 64) {
                throw new RuntimeException('OTLP request headers exceed budget');
            }
            if (
                !preg_match('/^[A-Za-z0-9!#$%&\x27*+.^_`|~-]+$/D', $name)
                || preg_match('/[\\x00-\\x1f\\x7f]/', $value)
            ) {
                throw new RuntimeException('Invalid OTLP header');
            }
            if (in_array(strtolower($name), array('host', 'content-length', 'transfer-encoding', 'connection', 'accept-encoding', 'expect'), true)) {
                continue;
            }
            $lines[] = $name . ': ' . $value;
        }
        if (strlen(implode("\r\n", $lines)) > ExportBudget::HEADER_BYTES) {
            throw new RuntimeException('OTLP request headers exceed budget');
        }
        if (!$stream && function_exists('curl_init')) {
            return $this->curl($url, $payload, $lines, $timeoutMillis, trim($parts['host'], '[]'));
        }
        return $this->stream($parts, $payload, $lines, $timeoutMillis);
    }

    /**
     * @param list<string> $lines
     * @return array{ok: bool, status: int, headers: array<string, string>, body: string}
     */
    private function curl(string $url, string $payload, array $lines, int $timeout, string $host): array
    {
        $version = curl_version();
        if (!filter_var($host, FILTER_VALIDATE_IP) && !($version['features'] & CURL_VERSION_ASYNCHDNS)) {
            throw new RuntimeException('Bounded DNS requires an asynchronous cURL resolver');
        }
        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('Cannot create OTLP transport');
        }
        $headers = array();
        $headerBytes = 0;
        $body = '';
        try {
            curl_setopt_array($curl, array(
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => $lines,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT_MS => $timeout,
                CURLOPT_CONNECTTIMEOUT_MS => $timeout,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_HEADERFUNCTION => function ($handle, $line) use (&$headers, &$headerBytes) {
                    $headerBytes += strlen($line);
                    if ($headerBytes > ExportBudget::HEADER_BYTES) {
                        return 0;
                    }
                    $this->header($line, $headers);
                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION => function ($handle, $chunk) use (&$body) {
                    if (strlen($chunk) > ExportBudget::RESPONSE_BYTES - strlen($body)) {
                        return 0;
                    }
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ));
            $ok = curl_exec($curl) !== false;
            $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $encoding = strtolower(isset($headers['content-encoding']) ? $headers['content-encoding'] : 'identity');
            return array('ok' => $ok && $status >= 200 && $status < 300 && $encoding === 'identity',
                'status' => $status, 'headers' => $headers, 'body' => $body);
        } finally {
            if (PHP_VERSION_ID < 80500) {
                curl_close($curl);
            }
        }
    }

    /**
     * No DNS or blocking HTTP wrapper here. Unbounded hostname resolution is
     * deliberately unavailable without cURL's asynchronous resolver.
     *
     * @param array<string, mixed> $parts
     * @param list<string> $lines
     * @return array{ok: bool, status: int, headers: array<string, string>, body: string}
     */
    private function stream(array $parts, string $payload, array $lines, int $timeout): array
    {
        $host = trim($parts['host'], '[]');
        $address = strtolower($host) === 'localhost' ? '127.0.0.1' : $host;
        if (!filter_var($address, FILTER_VALIDATE_IP)) {
            throw new RuntimeException('Stream OTLP requires a literal IP or localhost; install cURL for DNS');
        }
        $tls = strtolower($parts['scheme']) === 'https';
        $port = isset($parts['port']) ? $parts['port'] : ($tls ? 443 : 80);
        $authority = strpos($address, ':') !== false ? '[' . $address . ']' : $address;
        $deadline = ExportBudget::now() + $timeout / 1000;
        $context = stream_context_create(array('ssl' => array(
            'verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false,
            'peer_name' => $host, 'SNI_enabled' => true, 'disable_compression' => true,
        )));
        $socket = @stream_socket_client(
            'tcp://' . $authority . ':' . $port,
            $errno,
            $error,
            $timeout / 1000,
            STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
            $context
        );
        if ($socket === false) {
            throw new RuntimeException('OTLP connection failed');
        }
        try {
            stream_set_blocking($socket, false);
            $this->wait($socket, $deadline, true);
            if (@stream_socket_get_name($socket, true) === false) {
                throw new RuntimeException('OTLP connection failed');
            }
            if ($tls) {
                $method = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
                if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
                    $method |= constant('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT');
                }
                do {
                    $this->wait($socket, $deadline, true);
                    $ready = @stream_socket_enable_crypto($socket, true, $method);
                    if ($ready === false) {
                        throw new RuntimeException('OTLP TLS verification failed');
                    }
                    if ($ready === 0) {
                        usleep(1000);
                    }
                } while ($ready !== true);
            }
            $target = isset($parts['path']) && $parts['path'] !== '' ? $parts['path'] : '/';
            if (isset($parts['query'])) {
                $target .= '?' . $parts['query'];
            }
            if (preg_match('/[\\x00-\\x20\\x7f]/', $target)) {
                throw new RuntimeException('Invalid OTLP request target');
            }
            $hostHeader = strpos($host, ':') !== false ? '[' . $host . ']' : $host;
            $request = 'POST ' . $target . " HTTP/1.1\r\nHost: " . $hostHeader . ':' . $port
                . "\r\nContent-Length: " . strlen($payload) . "\r\n" . implode("\r\n", $lines) . "\r\n\r\n";
            $this->write($socket, $request, $deadline);
            $this->write($socket, $payload, $deadline);
            $buffer = '';
            while (($end = strpos($buffer, "\r\n\r\n")) === false) {
                if (strlen($buffer) > ExportBudget::HEADER_BYTES) {
                    throw new RuntimeException('OTLP response headers exceed budget');
                }
                $buffer .= $this->read($socket, $deadline);
            }
            if ($end + 4 > ExportBudget::HEADER_BYTES) {
                throw new RuntimeException('OTLP response headers exceed budget');
            }
            $head = substr($buffer, 0, $end);
            $body = substr($buffer, $end + 4);
            $headerLines = explode("\r\n", $head);
            if (!preg_match('#^HTTP/1\\.[01] ([0-9]{3})(?: |$)#', array_shift($headerLines), $match)) {
                throw new RuntimeException('Invalid OTLP HTTP response');
            }
            $status = (int) $match[1];
            $headers = array();
            foreach ($headerLines as $line) {
                $this->header($line, $headers);
            }
            if (isset($headers['content-encoding']) && strtolower($headers['content-encoding']) !== 'identity') {
                throw new RuntimeException('Unsupported stream response framing');
            }
            if (isset($headers['transfer-encoding'])) {
                if (strtolower($headers['transfer-encoding']) !== 'chunked' || isset($headers['content-length'])) {
                    throw new RuntimeException('Ambiguous stream response framing');
                }
                return array('ok' => $status >= 200 && $status < 300, 'status' => $status,
                    'headers' => $headers, 'body' => $this->chunked($socket, $body, $deadline));
            }
            $length = null;
            if (isset($headers['content-length'])) {
                if (
                    !ctype_digit($headers['content-length']) || strlen($headers['content-length']) > 8
                    || (int) $headers['content-length'] > ExportBudget::RESPONSE_BYTES
                ) {
                    throw new RuntimeException('OTLP response body exceeds budget');
                }
                $length = (int) $headers['content-length'];
            }
            while ($length === null || strlen($body) < $length) {
                if (strlen($body) > ExportBudget::RESPONSE_BYTES) {
                    throw new RuntimeException('OTLP response body exceeds budget');
                }
                if (feof($socket)) {
                    if ($length !== null && strlen($body) < $length) {
                        throw new RuntimeException('Truncated OTLP response');
                    }
                    break;
                }
                $body .= $this->read($socket, $deadline, true);
            }
            if (strlen($body) > ExportBudget::RESPONSE_BYTES) {
                throw new RuntimeException('OTLP response body exceeds budget');
            }
            return array('ok' => $status >= 200 && $status < 300, 'status' => $status,
                'headers' => $headers, 'body' => $length === null ? $body : substr($body, 0, $length));
        } finally {
            fclose($socket);
        }
    }

    /** @param resource $socket */
    private function chunked($socket, string $buffer, float $deadline): string
    {
        $body = '';
        $framing = 0;
        for ($chunks = 0; $chunks < 1024; $chunks++) {
            $line = $this->line($socket, $buffer, $deadline, $framing);
            $size = explode(';', $line, 2)[0];
            if (!preg_match('/^[0-9a-fA-F]{1,8}$/D', $size)) {
                throw new RuntimeException('Invalid chunked OTLP response');
            }
            $size = (int) hexdec($size);
            if ($size > ExportBudget::RESPONSE_BYTES - strlen($body)) {
                throw new RuntimeException('OTLP response body exceeds budget');
            }
            if ($size === 0) {
                while ($this->line($socket, $buffer, $deadline, $framing) !== '') {
                    // Trailers share the bounded framing budget and are never trusted as response headers.
                }
                return $body;
            }
            while (strlen($buffer) < $size + 2) {
                $buffer .= $this->read($socket, $deadline);
            }
            if (substr($buffer, $size, 2) !== "\r\n") {
                throw new RuntimeException('Invalid chunk delimiter');
            }
            $body .= substr($buffer, 0, $size);
            $buffer = substr($buffer, $size + 2);
            $framing += 2;
        }
        throw new RuntimeException('OTLP chunk count exceeds budget');
    }

    /** @param resource $socket */
    private function line($socket, string &$buffer, float $deadline, int &$framing): string
    {
        while (($end = strpos($buffer, "\r\n")) === false) {
            if (strlen($buffer) > 1024) {
                throw new RuntimeException('OTLP chunk framing exceeds budget');
            }
            $buffer .= $this->read($socket, $deadline);
        }
        $framing += $end + 2;
        if ($end > 1024 || $framing > ExportBudget::HEADER_BYTES) {
            throw new RuntimeException('OTLP chunk framing exceeds budget');
        }
        $line = substr($buffer, 0, $end);
        $buffer = substr($buffer, $end + 2);
        return $line;
    }

    /** @param array<string, string> $headers */
    private function header(string $line, array &$headers): void
    {
        $colon = strpos($line, ':');
        if ($colon !== false) {
            $name = strtolower(substr($line, 0, $colon));
            if (isset($headers[$name])) {
                throw new RuntimeException('Duplicate OTLP response header');
            }
            $headers[$name] = trim(substr($line, $colon + 1));
        }
    }

    /** @param resource $socket */
    private function wait($socket, float $deadline, bool $write = false): void
    {
        $remaining = $deadline - ExportBudget::now();
        if ($remaining <= 0) {
            throw new RuntimeException('OTLP export deadline exceeded');
        }
        $read = $write ? array() : array($socket);
        $writes = $write ? array($socket) : array();
        $except = null;
        $seconds = (int) $remaining;
        $ready = @stream_select($read, $writes, $except, $seconds, (int) (($remaining - $seconds) * 1000000));
        if (!$ready) {
            throw new RuntimeException('OTLP export deadline or connection failure');
        }
    }

    /** @param resource $socket */
    private function write($socket, string $data, float $deadline): void
    {
        for ($offset = 0; $offset < strlen($data);) {
            $this->wait($socket, $deadline, true);
            $written = @fwrite($socket, substr($data, $offset, 8192));
            if ($written === false || feof($socket)) {
                throw new RuntimeException('OTLP write failed');
            }
            $offset += $written;
        }
    }

    /** @param resource $socket */
    private function read($socket, float $deadline, bool $allowEof = false): string
    {
        $this->wait($socket, $deadline);
        $chunk = (string) @fread($socket, 8192);
        if ($chunk === '' && feof($socket) && !$allowEof) {
            throw new RuntimeException('Truncated OTLP headers');
        }
        return $chunk;
    }
}
