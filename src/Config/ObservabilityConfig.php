<?php

namespace Elven\Observability\PhpLegacy\Config;

final class ObservabilityConfig
{
    private $enabled;
    private $disabledReason;
    private $debug;
    private $serviceName;
    private $serviceNamespace;
    private $serviceVersion;
    private $environment;
    private $resourceAttributes;
    private $endpoint;
    private $tracesEndpoint;
    private $metricsEndpoint;
    private $logsEndpoint;
    private $protocol;
    private $headers;
    private $timeoutMillis;
    private $propagators;
    private $tracesExporter;
    private $metricsExporter;
    private $metricsTemporality;
    private $logsExporter;
    private $sampler;
    private $samplerArg;
    private $logCorrelationEnabled;
    private $redactionEnabled;
    private $captureDbStatement;
    private $redactDbStatement;
    private $allowRawAttributes;
    private $maxSpansPerRequest;
    private $maxMetricPointsPerRequest;
    private $maxLogRecordsPerRequest;
    private $fingerprint;

    public function __construct(array $values)
    {
        $this->enabled = (bool) $values['enabled'];
        $this->disabledReason = (string) $values['disabled_reason'];
        $this->debug = (bool) $values['debug'];
        $this->serviceName = (string) $values['service_name'];
        $this->serviceNamespace = (string) $values['service_namespace'];
        $this->serviceVersion = (string) $values['service_version'];
        $this->environment = (string) $values['environment'];
        $this->resourceAttributes = (array) $values['resource_attributes'];
        $this->endpoint = (string) $values['endpoint'];
        $this->tracesEndpoint = (string) $values['traces_endpoint'];
        $this->metricsEndpoint = (string) $values['metrics_endpoint'];
        $this->logsEndpoint = (string) $values['logs_endpoint'];
        $this->protocol = (string) $values['protocol'];
        $this->headers = (array) $values['headers'];
        $this->timeoutMillis = (int) $values['timeout_millis'];
        $this->propagators = (array) $values['propagators'];
        $this->tracesExporter = (string) $values['traces_exporter'];
        $this->metricsExporter = (string) $values['metrics_exporter'];
        $this->metricsTemporality = (string) $values['metrics_temporality'];
        $this->logsExporter = (string) $values['logs_exporter'];
        $this->sampler = (string) $values['sampler'];
        $this->samplerArg = (float) $values['sampler_arg'];
        $this->logCorrelationEnabled = (bool) $values['log_correlation_enabled'];
        $this->redactionEnabled = (bool) $values['redaction_enabled'];
        $this->captureDbStatement = (bool) $values['capture_db_statement'];
        $this->redactDbStatement = (bool) $values['redact_db_statement'];
        $this->allowRawAttributes = (array) $values['allow_raw_attributes'];
        $this->maxSpansPerRequest = (int) $values['max_spans_per_request'];
        $this->maxMetricPointsPerRequest = (int) $values['max_metric_points_per_request'];
        $this->maxLogRecordsPerRequest = (int) $values['max_log_records_per_request'];
        $this->fingerprint = $this->buildFingerprint();
    }

    public function isEnabled()
    {
        return $this->enabled;
    }

    public function disabledReason()
    {
        return $this->disabledReason;
    }

    public function isDebug()
    {
        return $this->debug;
    }

    public function serviceName()
    {
        return $this->serviceName;
    }

    public function serviceNamespace()
    {
        return $this->serviceNamespace;
    }

    public function serviceVersion()
    {
        return $this->serviceVersion;
    }

    public function environment()
    {
        return $this->environment;
    }

    public function resourceAttributes()
    {
        return $this->resourceAttributes;
    }

    public function endpoint()
    {
        return $this->endpoint;
    }

    public function tracesEndpoint()
    {
        return $this->tracesEndpoint;
    }

    public function metricsEndpoint()
    {
        return $this->metricsEndpoint;
    }

    public function logsEndpoint()
    {
        return $this->logsEndpoint;
    }

    public function protocol()
    {
        return $this->protocol;
    }

    public function headers()
    {
        return $this->headers;
    }

    public function timeoutMillis()
    {
        return $this->timeoutMillis;
    }

    public function propagators()
    {
        return $this->propagators;
    }

    public function tracesExporter()
    {
        return $this->tracesExporter;
    }

    public function metricsExporter()
    {
        return $this->metricsExporter;
    }

    public function metricsTemporality()
    {
        return $this->metricsTemporality;
    }

    public function logsExporter()
    {
        return $this->logsExporter;
    }

    public function sampler()
    {
        return $this->sampler;
    }

    public function samplerArg()
    {
        return $this->samplerArg;
    }

    public function logCorrelationEnabled()
    {
        return $this->logCorrelationEnabled;
    }

    public function redactionEnabled()
    {
        return $this->redactionEnabled;
    }

    public function captureDbStatement()
    {
        return $this->captureDbStatement;
    }

    public function redactDbStatement()
    {
        return $this->redactDbStatement;
    }

    public function allowRawAttributes()
    {
        return $this->allowRawAttributes;
    }

    public function maxSpansPerRequest()
    {
        return $this->maxSpansPerRequest;
    }

    public function maxMetricPointsPerRequest()
    {
        return $this->maxMetricPointsPerRequest;
    }

    public function maxLogRecordsPerRequest()
    {
        return $this->maxLogRecordsPerRequest;
    }

    public function hasPropagator($name)
    {
        return in_array(strtolower((string) $name), array_map('strtolower', $this->propagators), true);
    }

    public function fingerprint()
    {
        return $this->fingerprint;
    }

    private function buildFingerprint()
    {
        $values = array(
            'enabled' => $this->enabled,
            'debug' => $this->debug,
            'service_name' => $this->serviceName,
            'service_namespace' => $this->serviceNamespace,
            'service_version' => $this->serviceVersion,
            'environment' => $this->environment,
            'resource_attributes' => $this->resourceAttributes,
            'endpoint' => $this->endpoint,
            'traces_endpoint' => $this->tracesEndpoint,
            'metrics_endpoint' => $this->metricsEndpoint,
            'logs_endpoint' => $this->logsEndpoint,
            'protocol' => $this->protocol,
            'headers' => $this->headers,
            'timeout_millis' => $this->timeoutMillis,
            'propagators' => $this->propagators,
            'traces_exporter' => $this->tracesExporter,
            'metrics_exporter' => $this->metricsExporter,
            'metrics_temporality' => $this->metricsTemporality,
            'logs_exporter' => $this->logsExporter,
            'sampler' => $this->sampler,
            'sampler_arg' => $this->samplerArg,
            'log_correlation_enabled' => $this->logCorrelationEnabled,
            'redaction_enabled' => $this->redactionEnabled,
            'capture_db_statement' => $this->captureDbStatement,
            'redact_db_statement' => $this->redactDbStatement,
            'allow_raw_attributes' => $this->allowRawAttributes,
            'max_spans_per_request' => $this->maxSpansPerRequest,
            'max_metric_points_per_request' => $this->maxMetricPointsPerRequest,
            'max_log_records_per_request' => $this->maxLogRecordsPerRequest,
        );
        ksort($values);
        $encoded = json_encode($values);
        if ($encoded === false) {
            $encoded = serialize($values);
        }
        return sha1($encoded);
    }
}
