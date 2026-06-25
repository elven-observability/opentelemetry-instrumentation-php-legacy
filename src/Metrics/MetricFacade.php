<?php

namespace Elven\Observability\PhpLegacy\Metrics;

use Elven\Observability\PhpLegacy\Export\OtlpHttpJsonMetricExporter;
use Elven\Observability\PhpLegacy\Privacy\AttributeRedactor;
use Elven\Observability\PhpLegacy\Support\Clock;

final class MetricFacade
{
    private $exporter;
    private $redactor;
    private $defaultAttributes;
    private $requestAttributes;
    private $counters;
    private $histograms;
    private $gauges;
    private $counterData;
    private $histogramData;
    private $gaugeData;
    private $startTimeUnixNano;
    private $enabled;
    private $maxPoints;
    private $droppedMetricPoints;

    private static $allowedLabels = array(
        'service_name',
        'service_namespace',
        'environment',
        'route',
        'method',
        'status_code',
        'dependency_type',
        'dependency_name',
        'operation',
        'error_type',
        'traffic_source',
        'traffic_channel',
        'is_bot',
        'error_category',
        'cache_name',
        'result',
    );

    private static $defaultHistogramBounds = array(
        0.0,
        5.0,
        10.0,
        25.0,
        50.0,
        100.0,
        250.0,
        500.0,
        1000.0,
        2500.0,
        5000.0,
        10000.0,
    );

    // OpenTelemetry semconv recommended boundaries (seconds) for HTTP/RPC request
    // duration histograms. Used when a histogram is recorded with unit 's' so the
    // metric (e.g. http_server_request_duration_seconds) is percentile-usable and
    // matches the convention used by Beyla and the existing dashboards.
    private static $secondsHistogramBounds = array(
        0.005,
        0.01,
        0.025,
        0.05,
        0.075,
        0.1,
        0.25,
        0.5,
        0.75,
        1.0,
        2.5,
        5.0,
        7.5,
        10.0,
    );

    public function __construct(
        $exporter,
        AttributeRedactor $redactor,
        array $defaultAttributes = array(),
        $enabled = true,
        $maxPoints = 512
    ) {
        $this->exporter = $exporter instanceof OtlpHttpJsonMetricExporter ? $exporter : null;
        $this->redactor = $redactor;
        $this->defaultAttributes = $defaultAttributes;
        $this->requestAttributes = array();
        $this->enabled = (bool) $enabled;
        $this->maxPoints = (int) $maxPoints > 0 ? (int) $maxPoints : 512;
        $this->droppedMetricPoints = 0;
        $this->counters = array();
        $this->histograms = array();
        $this->gauges = array();
        $this->counterData = array();
        $this->histogramData = array();
        $this->gaugeData = array();
        $this->startTimeUnixNano = Clock::nowUnixNano();
    }

    public function counter($name)
    {
        $name = $this->normalizeMetricName($name);
        if (!isset($this->counters[$name])) {
            $this->counters[$name] = new Counter($this, $name);
        }
        return $this->counters[$name];
    }

    public function histogram($name, $unit = 'ms')
    {
        $name = $this->normalizeMetricName($name);
        if (!isset($this->histograms[$name])) {
            $this->histograms[$name] = new Histogram($this, $name, $unit);
        }
        return $this->histograms[$name];
    }

    public function gauge($name)
    {
        $name = $this->normalizeMetricName($name);
        if (!isset($this->gauges[$name])) {
            $this->gauges[$name] = new Gauge($this, $name);
        }
        return $this->gauges[$name];
    }

    public function setRequestAttributes(array $attributes)
    {
        $this->requestAttributes = $this->redactor->redactMetricLabels($attributes, self::$allowedLabels);
    }

    public function addRequestAttributes(array $attributes)
    {
        $this->setRequestAttributes(array_merge($this->requestAttributes, $attributes));
    }

    public function clearRequestAttributes()
    {
        $this->requestAttributes = array();
    }

    public function requestAttributes()
    {
        return $this->requestAttributes;
    }

    public function recordCounter($name, $value, array $attributes = array())
    {
        if (!$this->enabled) {
            return;
        }
        $name = $this->normalizeMetricName($name);
        $key = $this->pointKey($name, $attributes);
        if (!isset($this->counterData[$key]) && !$this->allowNewPoint()) {
            return;
        }
        if (!isset($this->counterData[$key])) {
            $this->counterData[$key] = $this->point($name, 'counter', $attributes, 0.0);
        }
        $this->counterData[$key]['value'] += $value;
        $this->counterData[$key]['timeUnixNano'] = Clock::nowUnixNano();
    }

    public function recordHistogram($name, $value, array $attributes = array(), $unit = 'ms')
    {
        if (!$this->enabled) {
            return;
        }
        $name = $this->normalizeMetricName($name);
        // Seconds-unit histograms (semconv durations like http.server.request.duration)
        // need second-scaled boundaries; the default boundaries are millisecond-scaled.
        $bounds = $unit === 's' ? self::$secondsHistogramBounds : self::$defaultHistogramBounds;
        $key = $this->pointKey($name, $attributes);
        if (!isset($this->histogramData[$key]) && !$this->allowNewPoint()) {
            return;
        }
        if (!isset($this->histogramData[$key])) {
            $point = $this->point($name, 'histogram', $attributes, 0.0);
            $point['count'] = 0;
            $point['sum'] = 0.0;
            $point['min'] = $value;
            $point['max'] = $value;
            $point['unit'] = $unit;
            $point['explicitBounds'] = $bounds;
            $point['bucketCounts'] = array_fill(0, count($bounds) + 1, 0);
            $this->histogramData[$key] = $point;
        }
        $this->histogramData[$key]['count']++;
        $this->histogramData[$key]['sum'] += $value;
        $this->histogramData[$key]['min'] = min($this->histogramData[$key]['min'], $value);
        $this->histogramData[$key]['max'] = max($this->histogramData[$key]['max'], $value);
        $bucket = $this->bucketIndex($value, $bounds);
        $this->histogramData[$key]['bucketCounts'][$bucket]++;
        $this->histogramData[$key]['timeUnixNano'] = Clock::nowUnixNano();
    }

    public function recordGauge($name, $value, array $attributes = array())
    {
        if (!$this->enabled) {
            return;
        }
        $name = $this->normalizeMetricName($name);
        $key = $this->pointKey($name, $attributes);
        if (!isset($this->gaugeData[$key]) && !$this->allowNewPoint()) {
            return;
        }
        $this->gaugeData[$key] = $this->point($name, 'gauge', $attributes, $value);
        $this->gaugeData[$key]['timeUnixNano'] = Clock::nowUnixNano();
    }

    public function collect()
    {
        $metrics = $this->snapshot();
        $this->clear();
        return $metrics;
    }

    public function forceFlush()
    {
        if (!$this->exporter) {
            $this->collect();
            return true;
        }

        $metrics = $this->snapshot();
        if (!$metrics) {
            return true;
        }

        $ok = $this->exporter->export($metrics);
        if ($ok) {
            $this->clear();
        } else {
            $this->recordCounter('elven.php.exporter.failed_exports', 1, array('operation' => 'metrics'));
        }
        return $ok;
    }

    private function point($name, $type, array $attributes, $value)
    {
        $attributes = array_merge($this->defaultAttributes, $this->requestAttributes, $attributes);
        $attributes = $this->redactor->redactMetricLabels($attributes, self::$allowedLabels);
        return array(
            'name' => $this->normalizeMetricName($name),
            'type' => $type,
            'attributes' => $attributes,
            'value' => (float) $value,
            'startTimeUnixNano' => $this->startTimeUnixNano,
            'timeUnixNano' => Clock::nowUnixNano(),
        );
    }

    private function pointKey($name, array $attributes)
    {
        $attributes = $this->redactor->redactMetricLabels(
            array_merge($this->defaultAttributes, $this->requestAttributes, $attributes),
            self::$allowedLabels
        );
        ksort($attributes);
        $encoded = json_encode($attributes);
        if ($encoded === false) {
            $encoded = serialize($attributes);
        }
        return $this->normalizeMetricName($name) . ':' . md5($encoded);
    }

    private function appendPoints(array &$metrics, array $data, $type)
    {
        foreach ($data as $point) {
            $name = $point['name'];
            if (!isset($metrics[$name])) {
                $metrics[$name] = array(
                    'name' => $name,
                    'type' => $type,
                    'unit' => isset($point['unit']) ? $point['unit'] : '',
                    'points' => array(),
                );
            }
            $metrics[$name]['points'][] = $point;
        }
    }

    private function snapshot()
    {
        $this->appendDroppedMetricPoint();
        $metrics = array();
        $this->appendPoints($metrics, $this->counterData, 'counter');
        $this->appendPoints($metrics, $this->histogramData, 'histogram');
        $this->appendPoints($metrics, $this->gaugeData, 'gauge');
        return array_values($metrics);
    }

    private function clear()
    {
        $this->counterData = array();
        $this->histogramData = array();
        $this->gaugeData = array();
    }

    private function allowNewPoint()
    {
        if ($this->pointCount() >= $this->maxPoints) {
            $this->droppedMetricPoints++;
            return false;
        }
        return true;
    }

    private function pointCount()
    {
        return count($this->counterData) + count($this->histogramData) + count($this->gaugeData);
    }

    private function appendDroppedMetricPoint()
    {
        if ($this->droppedMetricPoints <= 0) {
            return;
        }

        $attributes = array('operation' => 'metrics');
        $key = $this->pointKey('elven.php.exporter.dropped_metric_points', $attributes);
        if (!isset($this->counterData[$key])) {
            $this->counterData[$key] = $this->point('elven.php.exporter.dropped_metric_points', 'counter', $attributes, 0.0);
        }
        $this->counterData[$key]['value'] += $this->droppedMetricPoints;
        $this->counterData[$key]['timeUnixNano'] = Clock::nowUnixNano();
        $this->droppedMetricPoints = 0;
    }

    private function bucketIndex($value, array $bounds)
    {
        foreach ($bounds as $index => $bound) {
            if ($value <= $bound) {
                return $index;
            }
        }
        return count($bounds);
    }

    private function normalizeMetricName($name)
    {
        $name = preg_replace('/[^A-Za-z0-9_.-]/', '_', (string) $name);
        $name = trim($name, '_.-');
        if ($name === '') {
            return 'elven.php.metric.invalid';
        }
        return substr($name, 0, 255);
    }
}
