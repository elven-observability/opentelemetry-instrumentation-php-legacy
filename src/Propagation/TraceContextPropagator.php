<?php

namespace Elven\Observability\PhpLegacy\Propagation;

use Elven\Observability\PhpLegacy\Trace\SpanContext;

final class TraceContextPropagator
{
    public function extract(array $carrier)
    {
        $traceparent = $this->header($carrier, 'traceparent');
        if ($traceparent === null || $traceparent === '') {
            return SpanContext::invalid();
        }

        $parts = explode('-', trim($traceparent));
        if (count($parts) !== 4) {
            return SpanContext::invalid();
        }

        list($version, $traceId, $spanId, $traceFlags) = $parts;
        if (!preg_match('/^[0-9a-f]{2}$/', $version) || strtolower($version) === 'ff') {
            return SpanContext::invalid();
        }

        $traceState = $this->header($carrier, 'tracestate');
        return new SpanContext($traceId, $spanId, $traceFlags, $traceState ?: '', true, true);
    }

    public function inject(array &$carrier, SpanContext $context)
    {
        if (!$context->isValid()) {
            return;
        }
        $carrier['traceparent'] = $context->traceparent();
        if ($context->traceState() !== '') {
            $carrier['tracestate'] = $context->traceState();
        }
    }

    private function header(array $carrier, $name)
    {
        $normalized = strtolower($name);
        foreach ($carrier as $key => $value) {
            $candidate = strtolower(str_replace('_', '-', (string) $key));
            if ($candidate === $normalized || $candidate === 'http-' . $normalized) {
                return is_array($value) ? reset($value) : (string) $value;
            }
        }

        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($carrier[$serverKey])) {
            return (string) $carrier[$serverKey];
        }
        return null;
    }
}
