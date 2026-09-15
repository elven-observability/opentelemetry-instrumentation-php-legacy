<?php

namespace Elven\Observability\PhpLegacy\Privacy;

use Elven\Observability\PhpLegacy\Attribution\TrafficSourceResolver;
use Elven\Observability\PhpLegacy\Config\ObservabilityConfig;

final class AttributeRedactor
{
    const REDACTED = '[REDACTED]';

    /**
     * Length cap for consumer-owned outcome labels (`result`, `error_category`,
     * `dependency_type`). Deliberately much tighter than the general 160-char
     * bound: an outcome vocabulary is a handful of short tokens, so anything
     * longer is either free text or an identifier that slipped the id check.
     */
    const MAX_VOCABULARY_LABEL = 40;

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
        } elseif (in_array($key, array('dependency_type', 'result', 'error_category'), true)) {
            $value = $this->normalizeVocabularyLabel($value);
        } elseif ($key === 'cache_name') {
            $value = strtolower((string) preg_replace('/[^a-z0-9_.-]/i', '_', $value));
            if ($value === '' || UrlSanitizer::isHighCardinalityValue($value)) {
                $value = 'other';
            }
        } elseif ($key === 'is_bot') {
            $value = $this->enumLabel($value, array('true', 'false', 'unknown'));
        } elseif ($key === 'traffic_source') {
            $value = TrafficSourceResolver::normalizeSource($value);
        } elseif ($key === 'traffic_channel') {
            $value = TrafficSourceResolver::normalizeChannel($value);
        }
        return substr($value, 0, 160);
    }

    /**
     * Bounds an OUTCOME label without deciding what the outcome may be called.
     *
     * WHY THIS IS NOT AN ENUM ANY MORE.
     *
     * `result`, `error_category` and `dependency_type` used to be closed enums
     * owned by this library. Anything outside the list silently became `other` --
     * not dropped, not warned about: REPLACED, inside `MetricFacade::point()`,
     * which every metric of every consumer goes through.
     *
     * The enums were written from this library's own instrumentation, where
     * `result` really is `hit|miss|success|...`. But `result` is also the only
     * label a consumer has for the outcome of ITS OWN business operation, and no
     * list written here can anticipate `business_reject`, `requeue`, `exhausted`,
     * `suggested` or `variant`. Measured in a consumer (zupper-api, 2026-09-01):
     * `sum by (result)` over its checkout funnel returned exactly two values --
     * `success` and `other` -- with every business outcome collapsed into the
     * second. The dashboard looked healthy and answered nothing.
     *
     * 🔴 That failure mode is the worst kind: the metric is emitted, the query
     * runs, the panel renders, and the value is wrong. Nothing anywhere fails.
     *
     * WHAT REPLACES IT, AND WHY CARDINALITY IS STILL BOUNDED.
     *
     * The identifier defences `dependency_name`, `operation` and `error_type`
     * already get -- they are equally consumer-owned and were never enums -- plus
     * two bounds those three do not have (folding and a 40-char cap):
     *
     *   - normalized to a lowercase `[a-z0-9_.-]` token, so casing and spacing
     *     cannot split one outcome into several series;
     *   - `{id}` when it looks like an identifier, which is what actually causes a
     *     cardinality blow-up -- an id, a UUID, a message. `business_reject` is
     *     not a cardinality risk; `order-8F2A19C4B7E3` is, and is still caught;
     *   - capped at {@see self::MAX_VOCABULARY_LABEL} characters, well under the
     *     160 general bound, because an outcome token that long is a bug already;
     *   - empty becomes `unknown`, never the empty string.
     *
     * `is_bot` stays a strict enum on purpose: it is genuinely ternary, and a
     * fourth value there is a defect, not a vocabulary this library failed to
     * anticipate.
     *
     * @param mixed $value
     * @return string
     */
    private function normalizeVocabularyLabel($value)
    {
        $token = trim((string) $value);

        if ($token === '') {
            return 'unknown';
        }

        // Already a placeholder put there by `redactValue()` upstream
        // (`[REDACTED_EMAIL]`, `[REDACTED]`, ...): return it BYTE FOR BYTE.
        // Lower-casing it would emit `[redacted_email]` here and
        // `[REDACTED_EMAIL]` everywhere else -- two spellings of one thing, which
        // is a split series and a confusing panel.
        if (self::isPlaceholder($token)) {
            return $token;
        }

        $token = strtolower($token);

        // The id defences run FIRST, before the separator folding below: every
        // check must see the value the consumer sent. `sanitizePath()` catches a
        // run of four or more digits in any position (since 0.7.1; before that it
        // needed a word boundary and `reserva_201211` went through), and
        // `isHighCardinalityValue()` sees hex runs, UUIDs and opaque letter+digit
        // tokens.
        $token = UrlSanitizer::sanitizePath($token);
        if ($this->redactionEnabled) {
            $token = UrlSanitizer::redactSensitiveText($token);
        }
        if (UrlSanitizer::isHighCardinalityValue($token)) {
            return '{id}';
        }

        // Placeholder produced by the sanitiser just above (`{id}`, `{email}`):
        // folding it would turn `{id}` into `id`, which reads like a real outcome.
        if (self::isPlaceholder($token)) {
            return $token;
        }

        $token = (string) preg_replace('/[^a-z0-9_.-]+/', '_', $token);
        $token = trim($token, '_.-');

        if ($token === '') {
            return 'unknown';
        }

        if (strlen($token) > self::MAX_VOCABULARY_LABEL) {
            // Cut, THEN trim the separators again. Trimming only before the cut left
            // `hoteis_x_opcaohotelquartosdto_x_hotel_x_` when the 40th character was
            // a separator, and a second pass over that label (any path that redacts
            // twice) returned it without the `_`: one outcome, two series. Cannot
            // become empty: the first character is not a separator (trimmed above).
            $token = trim(substr($token, 0, self::MAX_VOCABULARY_LABEL), '_.-');
        }

        return $token;
    }

    /**
     * `{id}`, `{email}`, `[REDACTED]`... -- a marker, not an outcome. Recognised
     * by shape so a new marker elsewhere in the library is covered without a
     * parallel list here to forget to update.
     *
     * @param string $token
     * @return bool
     */
    private static function isPlaceholder($token)
    {
        return preg_match('/^(?:\{[A-Za-z0-9_]+\}|\[[A-Za-z0-9_]+\])$/', $token) === 1;
    }

    private function enumLabel($value, array $allowed)
    {
        $value = strtolower(trim((string) $value));
        return in_array($value, $allowed, true) ? $value : 'other';
    }
}
