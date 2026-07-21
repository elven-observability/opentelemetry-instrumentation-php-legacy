<?php

namespace Elven\Observability\PhpLegacy\Privacy;

use Elven\Observability\PhpLegacy\Attribution\TrafficSourceResolver;
use Elven\Observability\PhpLegacy\Config\ObservabilityConfig;

final class AttributeRedactor
{
    const REDACTED = '[REDACTED]';

    /** Per-key redaction plans. Attribute keys are a small, bounded, repeating
     *  set, so the (otherwise regex-heavy) key classification is computed once
     *  per key and memoized for the life of the process. */
    const PLAN_RAW = 0;
    const PLAN_REDACT = 1;
    const PLAN_DB = 2;
    const PLAN_HASH = 3;
    const PLAN_SCAN = 4;

    private $redactionEnabled;
    private $allowRaw;
    private $captureDbStatement;
    private $redactDbStatement;

    /** @var array<string,int> key => PLAN_* cache */
    private $keyPlanCache = array();

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
        $key = (string) $key;
        $plan = isset($this->keyPlanCache[$key])
            ? $this->keyPlanCache[$key]
            : ($this->keyPlanCache[$key] = $this->classifyKey($key));

        switch ($plan) {
            case self::PLAN_RAW:
                return $value;
            case self::PLAN_REDACT:
                return self::REDACTED;
            case self::PLAN_DB:
                if (!$this->captureDbStatement) {
                    return self::REDACTED;
                }
                return $this->redactDbStatement ? DbStatementSanitizer::sanitize($value) : $value;
            case self::PLAN_HASH:
                return $this->hashValue($value);
            default: // PLAN_SCAN
                return is_string($value) ? UrlSanitizer::redactSensitiveText($value) : $value;
        }
    }

    /**
     * Classifies an attribute key into a redaction plan. Pure function of the key
     * and the (immutable) config, so the result is safely memoizable per key.
     *
     * @return int one of the PLAN_* constants
     */
    private function classifyKey($key)
    {
        if (isset($this->allowRaw[$key])) {
            return self::PLAN_RAW;
        }
        if ($key === 'exception.message') {
            return self::PLAN_REDACT;
        }
        if ($this->isDbStatementKey($key)) {
            return self::PLAN_DB;
        }
        if ($this->isSensitiveKey($key)) {
            return self::PLAN_REDACT;
        }
        if ($this->isUserIdentifierKey($key)) {
            return self::PLAN_HASH;
        }
        return self::PLAN_SCAN;
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
        return in_array($key, array(
            'user.id',
            'enduser.id',
            'customer.id',
            'tenant.id',
            'organization.id',
        ), true);
    }

    private function isSensitiveKey($key)
    {
        return UrlSanitizer::isSensitiveKey($key)
            || preg_match('/authorization|cookie|set-cookie|token|password|passwd|secret|session|api[-_]?key|bearer/i', (string) $key) === 1;
    }

    private function hashValue($value)
    {
        if (is_string($value) && preg_match('/^[a-f0-9]{32}$/i', $value) === 1) {
            return strtolower($value);
        }
        return IdentifierHasher::hash($value);
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
        } elseif ($key === 'dependency_type') {
            $value = $this->enumLabel($value, array(
                'http', 'db', 'aws', 'cache', 'redis', 'memcached', 'mongo',
                'soap', 'rpc', 'amqp', 'messaging', 'mail', 'smtp', 'search',
            ));
        } elseif ($key === 'cache_name') {
            $value = strtolower((string) preg_replace('/[^a-z0-9_.-]/i', '_', $value));
            if ($value === '' || UrlSanitizer::isHighCardinalityValue($value)) {
                $value = 'other';
            }
        } elseif ($key === 'result') {
            $value = $this->enumLabel($value, array(
                'hit', 'miss', 'error', 'success', 'failure', 'timeout', 'unknown',
            ));
        } elseif ($key === 'error_category') {
            $value = $this->enumLabel($value, array('technical', 'client', 'dependency', 'timeout', 'unknown'));
        } elseif ($key === 'is_bot') {
            $value = $this->enumLabel($value, array('true', 'false', 'unknown'));
        } elseif ($key === 'traffic_source') {
            $value = TrafficSourceResolver::normalizeSource($value);
        } elseif ($key === 'traffic_channel') {
            $value = TrafficSourceResolver::normalizeChannel($value);
        }
        return substr($value, 0, 160);
    }

    private function enumLabel($value, array $allowed)
    {
        $value = strtolower(trim((string) $value));
        return in_array($value, $allowed, true) ? $value : 'other';
    }
}
