<?php

namespace Elven\Observability\PhpLegacy\Export;

use Elven\Observability\PhpLegacy\Config\ObservabilityConfig;
use Elven\Observability\PhpLegacy\Support\OtlpAttributes;
use Elven\Observability\PhpLegacy\Trace\Span;

final class OtlpHttpJsonTraceExporter
{
    private $endpoint;
    private $resource;
    private $client;

    public function __construct(ObservabilityConfig $config, array $resource)
    {
        $this->endpoint = $config->tracesEndpoint();
        $this->resource = $resource;
        $this->client = new HttpJsonClient($config->headers(), $config->timeoutMillis());
    }

    public function export(array $spans)
    {
        if (!$spans) {
            return true;
        }
        return $this->client->post($this->endpoint, $this->payload($spans));
    }

    public function payload(array $spans)
    {
        $encoded = array();
        foreach ($spans as $span) {
            if ($span instanceof Span) {
                $encoded[] = $this->encodeSpan($span);
            }
        }

        return array(
            'resourceSpans' => array(
                array(
                    'resource' => array('attributes' => OtlpAttributes::encode($this->resource)),
                    'scopeSpans' => array(
                        array(
                            'scope' => array('name' => 'elven-observability-php-legacy', 'version' => '0.1.0'),
                            'spans' => $encoded,
                        ),
                    ),
                ),
            ),
        );
    }

    private function encodeSpan(Span $span)
    {
        $events = array();
        foreach ($span->events() as $event) {
            $events[] = array(
                'name' => $event['name'],
                'timeUnixNano' => $event['timeUnixNano'],
                'attributes' => OtlpAttributes::encode($event['attributes']),
            );
        }

        $parent = $span->parentContext();
        $status = array('code' => 'STATUS_CODE_' . $span->statusCode());
        if ($span->statusMessage() !== '') {
            $status['message'] = $span->statusMessage();
        }

        $encoded = array(
            'traceId' => $span->context()->traceId(),
            'spanId' => $span->context()->spanId(),
            'name' => $span->name(),
            'kind' => 'SPAN_KIND_' . $span->kind(),
            'startTimeUnixNano' => $span->startTimeUnixNano(),
            'endTimeUnixNano' => $span->endTimeUnixNano(),
            'attributes' => OtlpAttributes::encode($span->attributes()),
            'events' => $events,
            'status' => $status,
        );
        if ($parent->isValid()) {
            $encoded['parentSpanId'] = $parent->spanId();
        }
        return $encoded;
    }
}
