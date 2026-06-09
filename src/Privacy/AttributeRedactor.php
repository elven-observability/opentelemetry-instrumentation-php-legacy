<?php

namespace Elven\Observability\PhpLegacy\Privacy;

use Elven\Observability\PhpLegacy\Attribution\TrafficSourceResolver;
use Elven\Observability\PhpLegacy\Config\ObservabilityConfig;

final class AttributeRedactor
{
    const REDACTED = '[REDACTED]';

    private $redactionEnabled;
    private $allowRaw;
    private $captureDbStatement;
    private $redactDbStatement;

    public function __construct(ObservabilityConfig $config)
    {
        $this->redactionEnabled = $config->redactionEnabled();
        $this->allowRaw = array_flip($config->allowRawAttributes());
        $this->captureDbStatement = $config->captureDbStatement();
        $this->redactDbStatement = $config->redactDbStatement();
    }

    public function redactAttributes(array $attributes)
    {
        $redacted = array();
        foreach ($attributes as $key => $value) {
            $redacted[$key] = $this->redactValue((string) $key, $value);
        }
        return $redacted;
    }

    public function redactMetricLabels(array $labels, array $allowedKeys)
    {
        $safe = array();
        $allowed = array_flip($allowedKeys);
        foreach ($labels as $key => $value) {
            if (!isset($allowed[$key])) {
                continue;
            }
            $safe[$key] = $this->normalizeMetricLabel($key, $value);
        }
        return $safe;
    }

    public function redactValue($key, $value)
    {
        if (!$this->redactionEnabled) {
            return $value;
        }
        if (isset($this->allowRaw[$key])) {
            return $value;
        }
        if ($key === 'exception.message') {
            return self::REDACTED;
        }
        if ($this->isDbStatementKey($key)) {
            if (!$this->captureDbStatement) {
                return self::REDACTED;
            }
            return $this->redactDbStatement ? DbStatementSanitizer::sanitize($value) : $value;
        }
        if ($this->isSensitiveKey($key)) {
            return self::REDACTED;
        }
        if ($this->isUserIdentifierKey($key)) {
            return $this->hashValue($value);
        }
        if (is_string($value)) {
            return UrlSanitizer::redactSensitiveText($value);
        }
        return $value;
    }

    public function redactHeaders(array $headers)
    {
        if (!$this->redactionEnabled) {
            return $headers;
        }
        $safe = array();
        foreach ($headers as $key => $value) {
            $safe[$key] = $this->isSensitiveKey($key) ? self::REDACTED : UrlSanitizer::redactSensitiveText((string) $value);
        }
        return $safe;
    }

    public function redactionEnabled()
    {
        return $this->redactionEnabled;
    }

    private function isDbStatementKey($key)
    {
        return in_array($key, array('db.statement', 'db.query.text', 'db.sql.text'), true)
            || strpos($key, 'db.query.parameter.') === 0
            || strpos($key, 'db.sql.parameter.') === 0;
    }

    private function isUserIdentifierKey($key)
    {
        return in_array($key, array('user.id', 'enduser.id', 'customer.id'), true);
    }

    private function isSensitiveKey($key)
    {
        return UrlSanitizer::isSensitiveKey($key)
            || preg_match('/authorization|cookie|set-cookie|token|password|passwd|secret|session|api[-_]?key|bearer/i', (string) $key) === 1;
    }

    private function hashValue($value)
    {
        return substr(hash('sha256', (string) $value), 0, 16);
    }

    private function normalizeMetricLabel($key, $value)
    {
        $value = (string) $this->redactValue($key, $value);
        if ($key === 'route') {
            $value = UrlSanitizer::sanitizePath($value);
        } elseif ($key === 'method') {
            $value = strtoupper($value);
            if (!in_array($value, array('GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'), true)) {
                $value = 'OTHER';
            }
        } elseif ($key === 'status_code') {
            $value = preg_match('/^[1-5][0-9]{2}$/', $value) === 1 ? $value : 'unknown';
        } elseif (in_array($key, array('dependency_name', 'operation', 'error_type'), true)) {
            $value = UrlSanitizer::sanitizePath($value);
            if ($this->redactionEnabled) {
                $value = UrlSanitizer::redactSensitiveText($value);
            }
            if (UrlSanitizer::isHighCardinalityValue($value)) {
                $value = '{id}';
            }
        } elseif ($key === 'traffic_source') {
            $value = TrafficSourceResolver::normalizeSource($value);
        } elseif ($key === 'traffic_channel') {
            $value = TrafficSourceResolver::normalizeChannel($value);
        }
        return substr($value, 0, 160);
    }
}
