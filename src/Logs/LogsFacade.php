<?php

namespace Elven\Observability\PhpLegacy\Logs;

use Elven\Observability\PhpLegacy\Config\ObservabilityConfig;
use Elven\Observability\PhpLegacy\Export\OtlpHttpJsonLogExporter;
use Elven\Observability\PhpLegacy\Metrics\MetricFacade;
use Elven\Observability\PhpLegacy\Privacy\AttributeRedactor;
use Elven\Observability\PhpLegacy\Privacy\UrlSanitizer;
use Elven\Observability\PhpLegacy\Support\Clock;

final class LogsFacade
{
    const MAX_BODY_BYTES = 8192;
    const MAX_ATTRIBUTE_BYTES = 2048;
    const MAX_ATTRIBUTE_COUNT = 64;
    const MAX_ARRAY_ITEMS = 32;
    const MAX_DEPTH = 4;

    private $config;
    private $tracer;
    private $hostname;
    private $exporter;
    private $redactor;
    private $metrics;
    private $maxRecords;
    private $records;
    private $droppedRecords;

    public function __construct(
        ObservabilityConfig $config,
        $tracer,
        $exporter = null,
        AttributeRedactor $redactor = null,
        MetricFacade $metrics = null,
        $maxRecords = 512
    ) {
        $this->config = $config;
        $this->tracer = $tracer;
        $this->hostname = php_uname('n');
        $this->exporter = $exporter instanceof OtlpHttpJsonLogExporter ? $exporter : null;
        $this->redactor = $redactor;
        $this->metrics = $metrics;
        $this->maxRecords = (int) $maxRecords > 0 ? (int) $maxRecords : 512;
        $this->records = array();
        $this->droppedRecords = 0;
    }

    public function correlate(array $context)
    {
        if (!$this->config->isEnabled() || !$this->config->logCorrelationEnabled()) {
            return $context;
        }

        $spanContext = method_exists($this->tracer, 'currentSpanContext')
            ? $this->tracer->currentSpanContext()
            : null;

        if ($spanContext && $spanContext->isValid()) {
            $context['trace_id'] = $spanContext->traceId();
            $context['span_id'] = $spanContext->spanId();
            $context['trace_flags'] = $spanContext->traceFlags();
        } else {
            $context['trace_id'] = isset($context['trace_id']) ? $context['trace_id'] : '';
            $context['span_id'] = isset($context['span_id']) ? $context['span_id'] : '';
            $context['trace_flags'] = isset($context['trace_flags']) ? $context['trace_flags'] : '';
        }

        $context['service_name'] = $this->config->serviceName();
        $context['environment'] = $this->config->environment();
        $context['hostname'] = $this->hostname;
        return $context;
    }

    public function emit($severityText, $body, array $attributes = array())
    {
        if (!$this->config->isEnabled() || !$this->exporter) {
            return true;
        }

        try {
            if (count($this->records) >= $this->maxRecords) {
                $this->droppedRecords++;
                return false;
            }

            $now = Clock::nowUnixNano();
            $spanContext = $this->currentSpanContext();
            $record = array(
                'timeUnixNano' => $now,
                'observedTimeUnixNano' => $now,
                'severityNumber' => $this->severityNumber($severityText),
                'severityText' => $this->normalizeSeverityText($severityText),
                'body' => $this->sanitizeBody($body),
                'attributes' => $this->sanitizeAttributes($this->correlate($attributes)),
                'traceId' => '',
                'spanId' => '',
            );

            if ($spanContext && $spanContext->isValid()) {
                $record['traceId'] = $spanContext->traceId();
                $record['spanId'] = $spanContext->spanId();
                $record['flags'] = $spanContext->traceFlags() === '01' ? 1 : 0;
            }

            $this->records[] = $record;
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function emitMonologRecord(array $record)
    {
        $severity = isset($record['level_name']) ? (string) $record['level_name'] : 'INFO';
        $body = isset($record['message']) ? $record['message'] : '';
        $attributes = array();

        if (isset($record['channel']) && $record['channel'] !== '') {
            $attributes['log.logger'] = $record['channel'];
        }
        if (isset($record['level']) && is_numeric($record['level'])) {
            $attributes['log.monolog.level'] = (int) $record['level'];
        }
        if (isset($record['context']) && is_array($record['context'])) {
            $attributes = array_merge($attributes, $this->prefixAttributes('log.context.', $record['context']));
        }
        if (isset($record['extra']) && is_array($record['extra'])) {
            $attributes = array_merge($attributes, $this->prefixAttributes('log.extra.', $record['extra']));
        }

        return $this->emit($severity, $body, $attributes);
    }

    public function forceFlush()
    {
        if (!$this->exporter) {
            $this->records = array();
            $this->droppedRecords = 0;
            return true;
        }

        try {
            $this->recordDroppedMetric();
            if (!$this->records) {
                return true;
            }
            $ok = $this->exporter->export($this->records);
            if ($ok) {
                $this->records = array();
            } else {
                $this->recordFailedExportMetric();
            }
            return $ok;
        } catch (\Throwable $e) {
            $this->recordFailedExportMetric();
            return false;
        }
    }

    private function currentSpanContext()
    {
        return method_exists($this->tracer, 'currentSpanContext')
            ? $this->tracer->currentSpanContext()
            : null;
    }

    private function sanitizeBody($body)
    {
        return $this->truncate($this->sanitizeValue('body', $body, 0), self::MAX_BODY_BYTES);
    }

    private function sanitizeAttributes(array $attributes)
    {
        $safe = array();
        $count = 0;
        foreach ($attributes as $key => $value) {
            if ($count >= self::MAX_ATTRIBUTE_COUNT) {
                $safe['log.attributes.dropped'] = 1;
                break;
            }
            $safe[(string) $key] = $this->truncate(
                $this->sanitizeValue((string) $key, $value, 0),
                self::MAX_ATTRIBUTE_BYTES
            );
            $count++;
        }
        return $safe;
    }

    private function sanitizeValue($key, $value, $depth)
    {
        if ($this->redactor && $this->isSensitiveKey($key)) {
            return AttributeRedactor::REDACTED;
        }

        if ($value instanceof \Throwable) {
            return '[exception ' . get_class($value) . ']';
        }
        if (is_object($value)) {
            return '[object ' . get_class($value) . ']';
        }
        if (is_array($value)) {
            if ($depth >= self::MAX_DEPTH) {
                return '[array]';
            }
            return $this->sanitizeArray($key, $value, $depth + 1);
        }

        $safe = $this->redactor
            ? $this->redactor->redactValue($key, $value)
            : UrlSanitizer::redactSensitiveText((string) $value);

        if (is_bool($safe) || is_int($safe) || is_float($safe)) {
            return $safe;
        }
        return UrlSanitizer::redactSensitiveText((string) $safe);
    }

    private function sanitizeArray($key, array $value, $depth)
    {
        $safe = array();
        $count = 0;
        foreach ($value as $itemKey => $itemValue) {
            if ($count >= self::MAX_ARRAY_ITEMS) {
                $safe['__dropped'] = count($value) - self::MAX_ARRAY_ITEMS;
                break;
            }
            $childKey = is_string($itemKey) ? $itemKey : $key;
            $safe[$itemKey] = $this->sanitizeValue($childKey, $itemValue, $depth);
            $count++;
        }

        $json = json_encode($safe);
        if ($json === false) {
            return '[array]';
        }
        return UrlSanitizer::redactSensitiveText($json);
    }

    private function prefixAttributes($prefix, array $attributes)
    {
        $prefixed = array();
        foreach ($attributes as $key => $value) {
            if ($key === 'exception' && $value instanceof \Throwable) {
                $prefixed['exception.type'] = get_class($value);
                $prefixed['exception.message'] = $value->getMessage();
                continue;
            }
            $prefixed[$prefix . $key] = $value;
        }
        return $prefixed;
    }

    private function severityNumber($severityText)
    {
        $severity = $this->normalizeSeverityText($severityText);
        $map = array(
            'TRACE' => 1,
            'DEBUG' => 5,
            'INFO' => 9,
            'NOTICE' => 10,
            'WARN' => 13,
            'WARNING' => 13,
            'ERROR' => 17,
            'CRITICAL' => 21,
            'ALERT' => 22,
            'EMERGENCY' => 24,
            'FATAL' => 21,
        );
        return isset($map[$severity]) ? $map[$severity] : 0;
    }

    private function normalizeSeverityText($severityText)
    {
        $severity = strtoupper(trim((string) $severityText));
        return $severity !== '' ? substr($severity, 0, 24) : 'UNSPECIFIED';
    }

    private function truncate($value, $maxBytes)
    {
        if (!is_string($value)) {
            return $value;
        }
        if (strlen($value) <= $maxBytes) {
            return $value;
        }
        return substr($value, 0, $maxBytes) . '...[truncated]';
    }

    private function isSensitiveKey($key)
    {
        return UrlSanitizer::isSensitiveKey($key)
            || preg_match('/authorization|cookie|set-cookie|token|password|passwd|secret|session|api[-_]?key|bearer/i', (string) $key) === 1;
    }

    private function recordDroppedMetric()
    {
        if ($this->droppedRecords <= 0 || !$this->metrics) {
            $this->droppedRecords = 0;
            return;
        }
        $this->metrics->counter('elven.php.exporter.dropped_log_records')->add($this->droppedRecords, array(
            'operation' => 'logs',
        ));
        $this->droppedRecords = 0;
    }

    private function recordFailedExportMetric()
    {
        if ($this->metrics) {
            $this->metrics->counter('elven.php.exporter.failed_exports')->add(1, array('operation' => 'logs'));
        }
    }
}
