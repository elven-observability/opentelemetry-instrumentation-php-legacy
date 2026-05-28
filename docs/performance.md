# Performance

## Targets

The target overhead is p95 below 3% in local request-loop benchmarks. The benchmark is intentionally simple and is used as an early warning, not as a replacement for staging measurements.

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
- Exporter failures are caught and guarded by a circuit breaker.

The cURL transport honors millisecond timeouts. The PHP stream fallback also uses sub-second timeouts, but `ext-curl` is still recommended for production because it gives more predictable connection handling under load.

OTLP log export adds network work during request shutdown. Keep it disabled when a separate file/stdout log agent already sends the same records to Loki, or enable it only after confirming duplicate volume is acceptable.

## PHP-FPM Notes

PHP-FPM is multi-process. Counters and histograms should be emitted as request-local deltas and aggregated by the Collector/Mimir pipeline. Process-global gauges are not ideal in this model; use them sparingly for per-request values such as peak memory.
