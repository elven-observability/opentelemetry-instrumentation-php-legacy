<?php

namespace Elven\Observability\PhpLegacy\Export;

use Elven\Observability\PhpLegacy\Config\ObservabilityConfig;
use Elven\Observability\PhpLegacy\Observability;
use Elven\Observability\PhpLegacy\Support\OtlpAttributes;

final class OtlpHttpJsonMetricExporter
{
    private $endpoint;
    private $resource;
    private $client;
    private $aggregationTemporality;

    public function __construct(ObservabilityConfig $config, array $resource)
    {
        $this->endpoint = $config->metricsEndpoint();
        $this->resource = $resource;
        $this->client = new HttpJsonClient($config->headers(), $config->timeoutMillis());
        $this->aggregationTemporality = $config->metricsTemporality() === 'delta'
            ? 'AGGREGATION_TEMPORALITY_DELTA'
            : 'AGGREGATION_TEMPORALITY_CUMULATIVE';
    }

    public function export(array $metrics)
    {
        if (!$metrics) {
            return true;
        }
        return $this->client->post($this->endpoint, $this->payload($metrics));
    }

    public function payload(array $metrics)
    {
        return array(
            'resourceMetrics' => array(
                array(
                    'resource' => array('attributes' => OtlpAttributes::encode($this->resource)),
                    'scopeMetrics' => array(
                        array(
                            'scope' => array('name' => Observability::SCOPE_NAME, 'version' => Observability::VERSION),
                            'metrics' => $this->encodeMetrics($metrics),
                        ),
                    ),
                ),
            ),
        );
    }

    private function encodeMetrics(array $metrics)
    {
        $encoded = array();
        foreach ($metrics as $metric) {
            if ($metric['type'] === 'counter') {
                $encoded[] = array(
                    'name' => $metric['name'],
                    'sum' => array(
                        'aggregationTemporality' => $this->aggregationTemporality,
                        'isMonotonic' => true,
                        'dataPoints' => $this->dataPoints($metric['points'], 'asDouble'),
                    ),
                );
            } elseif ($metric['type'] === 'histogram') {
                $encoded[] = array(
                    'name' => $metric['name'],
                    'unit' => isset($metric['unit']) ? $metric['unit'] : '',
                    'histogram' => array(
                        'aggregationTemporality' => $this->aggregationTemporality,
                        'dataPoints' => $this->histogramPoints($metric['points']),
                    ),
                );
            } elseif ($metric['type'] === 'gauge') {
                $encoded[] = array(
                    'name' => $metric['name'],
                    'gauge' => array(
                        'dataPoints' => $this->dataPoints($metric['points'], 'asDouble'),
                    ),
                );
            }
        }
        return $encoded;
    }

    private function dataPoints(array $points, $field)
    {
        $encoded = array();
        foreach ($points as $point) {
            $encoded[] = array(
                'attributes' => OtlpAttributes::encode($point['attributes']),
                'startTimeUnixNano' => $point['startTimeUnixNano'],
                'timeUnixNano' => $point['timeUnixNano'],
                $field => (float) $point['value'],
            );
        }
        return $encoded;
    }

    private function histogramPoints(array $points)
    {
        $encoded = array();
        foreach ($points as $point) {
            $bucketCounts = array();
            foreach ($point['bucketCounts'] as $count) {
                $bucketCounts[] = (string) $count;
            }
            $encoded[] = array(
                'attributes' => OtlpAttributes::encode($point['attributes']),
                'startTimeUnixNano' => $point['startTimeUnixNano'],
                'timeUnixNano' => $point['timeUnixNano'],
                'count' => (string) $point['count'],
                'sum' => (float) $point['sum'],
                'bucketCounts' => $bucketCounts,
                'explicitBounds' => $point['explicitBounds'],
                'min' => (float) $point['min'],
                'max' => (float) $point['max'],
            );
        }
        return $encoded;
    }
}
