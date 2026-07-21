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
        $endpointCircuitBreaker = $this->circuitBreaker
            ?: self::sharedCircuitBreaker('endpoint:' . self::endpointKey($url));
        $originCircuitBreaker = $this->circuitBreaker
            ?: self::sharedCircuitBreaker('origin:' . self::originKey($url));
        if (!$endpointCircuitBreaker->allowRequest()) {
            return false;
        }
        if ($originCircuitBreaker !== $endpointCircuitBreaker && !$originCircuitBreaker->allowRequest()) {
            return false;
        }

        try {
            $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($json === false) {
                $endpointCircuitBreaker->recordFailure();
                return false;
            }
            $result = function_exists('curl_init')
                ? $this->postWithCurl($url, $json)
                : $this->postWithStream($url, $json);
            if ($result['ok']) {
                $endpointCircuitBreaker->recordSuccess();
                if ($originCircuitBreaker !== $endpointCircuitBreaker) {
                    $originCircuitBreaker->recordSuccess();
                }
                return true;
            }

            $endpointCircuitBreaker->recordFailure();
            if ($originCircuitBreaker !== $endpointCircuitBreaker) {
                if ($result['origin_failure']) {
                    $originCircuitBreaker->recordFailure();
                } else {
                    $originCircuitBreaker->recordSuccess();
                }
            }
            return false;
        } catch (\Throwable $e) {
            $endpointCircuitBreaker->recordFailure();
            if ($originCircuitBreaker !== $endpointCircuitBreaker) {
                $originCircuitBreaker->recordFailure();
            }
            return false;
        }
    }

    private static function sharedCircuitBreaker($key)
    {
        if (!isset(self::$sharedCircuitBreakers[$key])) {
            if (count(self::$sharedCircuitBreakers) >= self::MAX_SHARED_CIRCUIT_BREAKERS) {
                return new CircuitBreaker(3, 30000, $key);
            }
            self::$sharedCircuitBreakers[$key] = new CircuitBreaker(3, 30000, $key);
        }
        return self::$sharedCircuitBreakers[$key];
    }

    private static function endpointKey($url)
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

    private static function originKey($url)
    {
        $parts = @parse_url((string) $url);
        if (!is_array($parts)) {
            return (string) $url;
        }
        $scheme = isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : 'http';
        $host = isset($parts['host']) ? strtolower((string) $parts['host']) : '';
        $port = isset($parts['port']) ? ':' . (string) $parts['port'] : '';
        return $scheme . '://' . $host . $port;
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
        return array(
            'ok' => $errno === 0 && $status >= 200 && $status < 300,
            'origin_failure' => $errno !== 0 || $status === 0 || $status === 408 || $status === 429 || $status >= 500,
        );
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
            return array('ok' => false, 'origin_failure' => true);
        }
        $metadata = stream_get_meta_data($stream);
        fclose($stream);
        $headers = isset($metadata['wrapper_data']) && is_array($metadata['wrapper_data'])
            ? $metadata['wrapper_data']
            : array();
        $status = 0;
        if (isset($headers[0]) && preg_match('#HTTP/\\S+\\s+(\\d{3})#', $headers[0], $matches) === 1) {
            $status = (int) $matches[1];
        }
        return array(
            'ok' => $status >= 200 && $status < 300,
            'origin_failure' => $status === 0 || $status === 408 || $status === 429 || $status >= 500,
        );
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
