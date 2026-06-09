# Environment Variables

## Core

| Variable | Default | Notes |
| --- | --- | --- |
| `ELVEN_OTEL_ENABLED` | `true` | Total kill switch. `false` makes all telemetry no-op and wins over explicit `init()` config. |
| `OTEL_SERVICE_NAME` | `unknown-service` | Required in production. |
| `OTEL_SERVICE_NAMESPACE` | empty | Example: `booking`. |
| `OTEL_SERVICE_VERSION` | `0.0.0` | Application/library version. |
| `ELVEN_ENVIRONMENT` | `unknown` | Preferred Elven env name. |
| `OTEL_DEPLOYMENT_ENVIRONMENT` | `unknown` | Used when `ELVEN_ENVIRONMENT` is absent. |
| `OTEL_RESOURCE_ATTRIBUTES` | empty | Comma-separated `key=value` list. |

## Exporter

| Variable | Default | Notes |
| --- | --- | --- |
| `OTEL_EXPORTER_OTLP_ENDPOINT` | `http://localhost:4318` | Base Collector endpoint. |
| `OTEL_EXPORTER_OTLP_TRACES_ENDPOINT` | `${endpoint}/v1/traces` | Signal-specific override. |
| `OTEL_EXPORTER_OTLP_METRICS_ENDPOINT` | `${endpoint}/v1/metrics` | Signal-specific override. |
| `OTEL_EXPORTER_OTLP_LOGS_ENDPOINT` | `${endpoint}/v1/logs` | Signal-specific override for OTLP logs. |
| `OTEL_EXPORTER_OTLP_PROTOCOL` | `http/json` | `http/json` is supported in v1. |
| `OTEL_EXPORTER_OTLP_HEADERS` | empty | Comma-separated `Header=value` list. |
| `OTEL_EXPORTER_OTLP_TIMEOUT` | `200` | Milliseconds. |
| `ELVEN_OTEL_EXPORT_TIMEOUT_MS` | `200` | Preferred Elven timeout override. |

`http/protobuf` is intentionally not exported in v1. If configured, telemetry becomes no-op with a debug reason rather than risking malformed payloads on PHP 7.3.

## Traces

| Variable | Default | Notes |
| --- | --- | --- |
| `OTEL_PROPAGATORS` | `tracecontext,baggage` | Supported values for this library. |
| `OTEL_TRACES_EXPORTER` | `otlp` | Use `none` to keep context/log correlation without exporting spans. |
| `OTEL_TRACES_SAMPLER` | `parentbased_traceidratio` | Also supports `always_on`, `always_off`. |
| `OTEL_TRACES_SAMPLER_ARG` | `1` | Ratio between `0` and `1`. |
| `ELVEN_OTEL_MAX_SPANS_PER_REQUEST` | `128` | Excess spans are dropped and counted. |

## Metrics

| Variable | Default | Notes |
| --- | --- | --- |
| `OTEL_METRICS_EXPORTER` | `otlp` | Use `none` to disable metric export. |
| `ELVEN_OTEL_MAX_METRIC_POINTS_PER_REQUEST` | `512` | Excess metric series in one request/job are dropped and counted. |

Metric labels are restricted to low-cardinality keys: `service_name`, `service_namespace`, `environment`, `route`, `method`, `status_code`, `dependency_type`, `dependency_name`, `operation`, `error_type`, `traffic_source`, `traffic_channel`.

## Logs

| Variable | Default | Notes |
| --- | --- | --- |
| `OTEL_LOGS_EXPORTER` | `none` | Use `otlp` to export OTLP logs to Collector `/v1/logs`; keep `none` if logs are already scraped and you only need correlation. |
| `ELVEN_OTEL_LOG_CORRELATION_ENABLED` | `true` | Adds trace fields to existing logs and OTLP log attributes. |
| `ELVEN_OTEL_MAX_LOG_RECORDS_PER_REQUEST` | `512` | Excess OTLP log records in one request/job are dropped and counted. |

The PHP app sends OTLP logs to the OpenTelemetry Collector, not directly to Loki. Configure the Collector logs pipeline to forward to Loki.

## Privacy

| Variable | Default | Notes |
| --- | --- | --- |
| `ELVEN_OTEL_REDACTION_ENABLED` | `true` | Global library-side redaction switch. Set to `false`, `off`, `0`, or `no` only when the customer explicitly owns redaction in the Collector/backend. This does not disable metric label allowlists/cardinality guardrails. |
| `ELVEN_OTEL_CAPTURE_DB_STATEMENT` | `false` | Raw statement capture is off. |
| `ELVEN_OTEL_REDACT_DB_STATEMENT` | `true` | Sanitizes statements if capture is enabled. |
| `ELVEN_OTEL_ALLOW_RAW_ATTRIBUTES` | empty | Explicit allowlist for controlled troubleshooting. |
| `ELVEN_OTEL_CAPTURE_CLIENT_ADDRESS` | `false` | Opt-in for raw `client.address`; leave off unless policy permits IP capture. |
| `ELVEN_OTEL_DEBUG` | `false` | Keep off in production unless diagnosing setup. |
