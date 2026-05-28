<?php

namespace Elven\Observability\PhpLegacy\Logs;

use Elven\Observability\PhpLegacy\Config\ObservabilityConfig;

final class LogsFacade
{
    private $config;
    private $tracer;
    private $hostname;

    public function __construct(ObservabilityConfig $config, $tracer)
    {
        $this->config = $config;
        $this->tracer = $tracer;
        $this->hostname = php_uname('n');
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
}
