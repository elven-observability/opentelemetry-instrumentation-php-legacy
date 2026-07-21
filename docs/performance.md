# Performance

## Targets

The target overhead is p95 below 3% in the local CPU-bound request-loop benchmark. The benchmark reports two values: observed ABBA-paired wall-clock p95 (kept visible because containers can be noisy) and the intrinsic span p95 delta normalized by the representative operation. CI gates on the normalized intrinsic value and warns, without hiding it, when wall-clock scheduling noise exceeds the budget. This is an early warning, not proof for the customer's workload. Staging canary latency and PHP-FPM saturation are authoritative.

Run:

```bash
docker compose run --rm php73 composer bench
```

## Runtime Controls

- `ELVEN_OTEL_ENABLED=false` disables all telemetry.
- `ELVEN_OTEL_EXPORT_TIMEOUT_MS=200` keeps export work bounded.
- `ELVEN_OTEL_MAX_SPANS_PER_REQUEST=128` prevents runaway span growth.
- `ELVEN_OTEL_MAX_METRIC_POINTS_PER_REQUEST=512` prevents runaway metric series growth.
- `ELVEN_OTEL_MAX_LOG_RECORDS_PER_REQUEST=512` prevents runaway OTLP log buffering.
- Span attributes/events and their value sizes have independent hard limits.
- `ELVEN_OTEL_METRICS_TEMPORALITY=cumulative` keeps counters/histograms compatible with Collector `prometheusremotewrite` into Mimir.
- Exporter failures are caught and guarded by endpoint and Collector-origin circuit breakers shared across PHP-FPM workers.

The cURL transport honors millisecond connect and total timeouts. The PHP stream fallback also supports sub-second timeouts, but `ext-curl` is recommended for production because connection behavior is more predictable under load.

Traces, logs, and metrics flush sequentially. If all three exporters are enabled and the Collector is unreachable, the first failing request can spend up to roughly three export timeouts. Those transport failures open the shared Collector-origin breaker; subsequent PHP-FPM requests and workers skip export until a single half-open probe is allowed after 30 seconds. Keep the default `200ms`, use a local Collector, and never copy backend-scale timeouts such as `10s` into a synchronous PHP-FPM exporter. The private temporary directory must be writable for cross-request breaker state; otherwise protection remains request-local.

OTLP log export adds network work during request shutdown. Keep it disabled when a separate file/stdout log agent already sends the same records to Loki, or enable it only after confirming duplicate volume is acceptable.

## PHP-FPM Notes

PHP-FPM is multi-process. Counters and histograms are collected as request-local aggregates and exported as cumulative OTLP by default so Prometheus remote write pipelines can translate them into Mimir time series. Process-global gauges are not ideal in this model; use them sparingly for per-request values such as peak memory.

The resource intentionally omits `process.pid`; promoting worker PID into Mimir labels causes time-series churn whenever FPM recycles workers. Route names and dependency names must also stay templated/bounded.

## Hot Paths

- Disabled telemetry returns no-op objects and skips cache metrics.
- Traffic attribution copies only a small allowlist from request globals; it never merges a potentially large POST body.
- Attribute key classification is memoized, while each value is still scanned.
- Sampling reduces export volume; lightweight span objects still exist so nested context propagates correctly. Keep span limits bounded even when sampling below 100%.
- Never export per-query or per-log synchronously. Signals buffer within request-local limits and flush once.
