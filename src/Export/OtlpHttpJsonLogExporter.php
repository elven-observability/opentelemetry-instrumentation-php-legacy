<?php

namespace Elven\Observability\PhpLegacy\Export;

use Elven\Observability\PhpLegacy\Config\ObservabilityConfig;
use Elven\Observability\PhpLegacy\Observability;
use Elven\Observability\PhpLegacy\Support\OtlpAttributes;

final class OtlpHttpJsonLogExporter
{
    private $endpoint;
    private $resource;
    private $client;

    public function __construct(ObservabilityConfig $config, array $resource)
    {
        $this->endpoint = $config->logsEndpoint();
        $this->resource = $resource;
        $this->client = new HttpJsonClient($config->headers(), $config->timeoutMillis());
    }

    public function export(array $records)
    {
        if (!$records) {
            return true;
        }
        return $this->client->post($this->endpoint, $this->payload($records));
    }

    public function payload(array $records)
    {
        return array(
            'resourceLogs' => array(
                array(
                    'resource' => array('attributes' => OtlpAttributes::encode($this->resource)),
                    'scopeLogs' => array(
                        array(
                            'scope' => array('name' => Observability::SCOPE_NAME, 'version' => Observability::VERSION),
                            'logRecords' => $this->encodeRecords($records),
                        ),
                    ),
                ),
            ),
        );
    }

    private function encodeRecords(array $records)
    {
        $encoded = array();
        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }
            $logRecord = array(
                'timeUnixNano' => (string) $record['timeUnixNano'],
                'observedTimeUnixNano' => (string) $record['observedTimeUnixNano'],
                'severityNumber' => (int) $record['severityNumber'],
                'severityText' => (string) $record['severityText'],
                'body' => OtlpAttributes::value($record['body']),
                'attributes' => OtlpAttributes::encode($record['attributes']),
            );

            if (isset($record['traceId']) && $record['traceId'] !== '') {
                $logRecord['traceId'] = (string) $record['traceId'];
            }
            if (isset($record['spanId']) && $record['spanId'] !== '') {
                $logRecord['spanId'] = (string) $record['spanId'];
            }
            if (isset($record['flags'])) {
                $logRecord['flags'] = (int) $record['flags'];
            }

            $encoded[] = $logRecord;
        }
        return $encoded;
    }
}
