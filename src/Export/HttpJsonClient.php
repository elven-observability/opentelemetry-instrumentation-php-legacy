<?php

namespace Elven\Observability\PhpLegacy\Export;

use Elven\Observability\PhpLegacy\Support\HeaderSanitizer;

final class HttpJsonClient
{
    const MAX_SHARED_CIRCUIT_BREAKERS = 16;

    private $headers;
    private $timeoutMillis;
    private $circuitBreaker;
    private $budget;
    private static $sharedCircuitBreakers = array();
    private static $exporting = false;

    public function __construct(array $headers, $timeoutMillis, ?CircuitBreaker $circuitBreaker = null, ?ExportBudget $budget = null)
    {
        $this->headers = $headers;
        $this->timeoutMillis = (int) $timeoutMillis;
        $this->circuitBreaker = $circuitBreaker;
        $this->budget = $budget ?: new ExportBudget((int) $timeoutMillis);
    }

    public function post($url, array $payload)
    {
        if (self::$exporting) {
            return false;
        }
        self::$exporting = true;
        $owner = $this->budget->enter();
        try {
            return $this->postOnce($url, $payload);
        } finally {
            $this->budget->leave($owner);
            self::$exporting = false;
        }
    }

    private function postOnce($url, array $payload)
    {
        $parts = @parse_url((string) $url);
        if (
            !is_array($parts) || !isset($parts['host'])
            || !in_array(strtolower(isset($parts['scheme']) ? $parts['scheme'] : ''), array('http', 'https'), true)
        ) {
            return false;
        }
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
            if ($this->budget->remainingMillis() === 0) {
                return false;
            }
            $json = LimitedJson::encode($payload);
            if ($json === null || !$this->budget->spendWire(strlen($json))) {
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
        return $this->exchange($url, $json, false);
    }

    private function postWithStream($url, $json)
    {
        return $this->exchange($url, $json, true);
    }

    private function exchange($url, $json, $stream)
    {
        $headers = array();
        foreach ($this->headerLines() as $line) {
            list($name, $value) = explode(':', $line, 2);
            $headers[$name] = trim($value);
        }
        try {
            $result = (new BoundedHttpExchange())->post(
                (string) $url,
                $json,
                $headers,
                min($this->timeoutMillis, $this->budget->remainingMillis()),
                $stream
            );
            $result['origin_failure'] = !$result['ok'] && ($result['status'] < 400
                || $result['status'] === 408 || $result['status'] === 429 || $result['status'] >= 500);
            return $result;
        } catch (\Throwable $ignored) {
            return array('ok' => false, 'origin_failure' => true);
        }
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
