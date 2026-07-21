<?php

namespace Elven\Observability\PhpLegacy\Export;

use Elven\Observability\PhpLegacy\Support\HeaderSanitizer;

final class HttpJsonClient
{
    const MAX_SHARED_CIRCUIT_BREAKERS = 16;

    private $headers;
    private $timeoutMillis;
    private $circuitBreaker;
    private static $sharedCircuitBreakers = array();

    public function __construct(array $headers, $timeoutMillis, ?CircuitBreaker $circuitBreaker = null)
    {
        $this->headers = $headers;
        $this->timeoutMillis = (int) $timeoutMillis;
        $this->circuitBreaker = $circuitBreaker;
    }

    public function post($url, array $payload)
    {
        $circuitBreaker = $this->circuitBreaker ?: self::sharedCircuitBreaker($url);
        if (!$circuitBreaker->allowRequest()) {
            return false;
        }

        try {
            $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($json === false) {
                $circuitBreaker->recordFailure();
                return false;
            }
            $ok = function_exists('curl_init')
                ? $this->postWithCurl($url, $json)
                : $this->postWithStream($url, $json);
            $ok ? $circuitBreaker->recordSuccess() : $circuitBreaker->recordFailure();
            return $ok;
        } catch (\Throwable $e) {
            $circuitBreaker->recordFailure();
            return false;
        }
    }

    private static function sharedCircuitBreaker($url)
    {
        $key = self::circuitBreakerKey($url);
        if (!isset(self::$sharedCircuitBreakers[$key])) {
            if (count(self::$sharedCircuitBreakers) >= self::MAX_SHARED_CIRCUIT_BREAKERS) {
                return new CircuitBreaker();
            }
            self::$sharedCircuitBreakers[$key] = new CircuitBreaker();
        }
        return self::$sharedCircuitBreakers[$key];
    }

    private static function circuitBreakerKey($url)
    {
        $parts = @parse_url((string) $url);
        if (!is_array($parts)) {
            return (string) $url;
        }
        $scheme = isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : 'http';
        $host = isset($parts['host']) ? strtolower((string) $parts['host']) : '';
        $port = isset($parts['port']) ? ':' . (string) $parts['port'] : '';
        $path = isset($parts['path']) ? (string) $parts['path'] : '';
        return $scheme . '://' . $host . $port . $path;
    }

    private function postWithCurl($url, $json)
    {
        $handle = curl_init($url);
        if (!$handle) {
            return false;
        }
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, $json);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($handle, CURLOPT_HEADER, false);
        curl_setopt($handle, CURLOPT_WRITEFUNCTION, function ($curl, $data) {
            return strlen($data);
        });
        curl_setopt($handle, CURLOPT_HTTPHEADER, $this->headerLines());
        curl_setopt($handle, CURLOPT_TIMEOUT_MS, $this->timeoutMillis);
        curl_setopt($handle, CURLOPT_CONNECTTIMEOUT_MS, $this->timeoutMillis);
        curl_exec($handle);
        $errno = curl_errno($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        if (PHP_VERSION_ID < 80500) {
            curl_close($handle);
        }
        return $errno === 0 && $status >= 200 && $status < 300;
    }

    private function postWithStream($url, $json)
    {
        $headers = implode("\r\n", $this->headerLines());
        $context = stream_context_create(array(
            'http' => array(
                'method' => 'POST',
                'header' => $headers,
                'content' => $json,
                'timeout' => max(0.001, $this->timeoutMillis / 1000),
                'ignore_errors' => true,
            ),
        ));
        $stream = @fopen($url, 'rb', false, $context);
        if ($stream === false) {
            return false;
        }
        $metadata = stream_get_meta_data($stream);
        fclose($stream);
        $headers = isset($metadata['wrapper_data']) && is_array($metadata['wrapper_data'])
            ? $metadata['wrapper_data']
            : array();
        return isset($headers[0]) && preg_match('#HTTP/\\S+\\s+2\\d\\d#', $headers[0]) === 1;
    }

    private function headerLines()
    {
        $headers = array('Content-Type' => 'application/json');
        foreach ($this->headers as $key => $value) {
            $name = HeaderSanitizer::sanitizeName($key);
            if ($name !== '') {
                $headers[$name] = HeaderSanitizer::sanitizeValue($value);
            }
        }
        return HeaderSanitizer::toHeaderLines($headers);
    }
}
