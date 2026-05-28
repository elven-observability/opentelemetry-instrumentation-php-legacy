# Software Design Document

## Goal

Provide production-safe OpenTelemetry-compatible instrumentation for PHP legacy applications that cannot rely on PECL `opentelemetry` or the official PHP SDK. The primary target is legacy/custom PHP applications, including Slim 2 style routing, with Composer platform compatibility down to `php=7.3.13`.

## Architecture

The library is intentionally small and explicit:

- `Config\EnvConfigResolver` resolves explicit init options, environment variables, and defaults. `ELVEN_OTEL_ENABLED=false` is the one precedence exception: it is a total runtime kill switch and always wins.
- `Resource\ResourceBuilder` creates stable service, runtime, host, process, and deployment attributes.
- `Propagation\TraceContextPropagator` implements W3C `traceparent`/`tracestate`.
- `Propagation\BaggagePropagator` implements bounded basic baggage.
- `Trace\Tracer`, `Trace\Span`, and `Trace\SpanProcessor` manage active spans, nesting, sampling, limits, and request-local flushing.
- `Trace\Sampler\ParentBasedTraceIdRatioSampler` implements `parentbased_traceidratio`, `always_on`, and `always_off`.
- `Export\OtlpHttpJsonTraceExporter` and `Export\OtlpHttpJsonMetricExporter` encode OTLP JSON and send HTTP POSTs to Collector endpoints.
- `Metrics\MetricFacade` provides counters, histograms, and gauges with low-cardinality label enforcement.
- `Logs\MonologTraceProcessor` and `Logs\LogsFacade` add correlation fields to existing logs.
- `Privacy` classes redact sensitive attributes, URLs, and DB statements.
- `Instrumentation` classes provide manual opt-in wrappers for HTTP server/client, cURL, Guzzle, SOAP/WCF, DB, Redis, Memcached, Mongo, AMQP, mail, AWS, and search clients.

## Lifecycle

`Observability::init()` is idempotent for identical config and reinitializes safely when explicit config changes, flushing the previous handle first. It registers one shutdown function, resolves config, builds resources, creates exporters, refreshes incoming W3C context from `$_SERVER` when no span is active, and returns an `ObservabilityHandle`.

`forceFlush()` flushes ended spans first and metrics second, returning `false` when either exporter fails. Failed exports are retained in bounded request-local buffers for a later retry, and exporter failures are counted as internal metrics. `shutdown()` records peak memory, calls `forceFlush()`, clears active span scope, and invalidates stale root context.

## Export Strategy

OTLP HTTP/JSON is the v1 wire format because it is supported by the OTLP spec and avoids PHP 7.3 protobuf dependency risk. Payloads follow the resource/scope/span and resource/scope/metric OTLP JSON shape.

The app should export to a customer/local Collector. The application does not need Elven backend credentials when using that path.

## Privacy Strategy

The library never captures request bodies, response bodies, SOAP XML, raw message payloads, raw Redis keys, raw Mongo queries, raw Elasticsearch queries, or raw DB statements by default.

Sensitive headers, paths, query values, exception messages, and baggage values are redacted before storage/export. Explicit user identifiers are hashed. Metric labels are allowlisted, normalized, and bounded to prevent accidental cardinality explosions.

## Failure Model

Telemetry must not break the application. The exporter uses short timeouts, catches all throwables, sanitizes outgoing headers, returns failure status instead of throwing, and includes a circuit breaker. Unsupported `http/protobuf` disables telemetry safely instead of attempting a risky partial implementation.

## Tradeoffs

- Manual wrappers are used instead of auto-instrumentation because the target app is Slim 2/custom legacy PHP.
- Metrics are request-local deltas because PHP-FPM multi-process workers are not a reliable source for process-global gauges.
- Long-running workers must call `init()` and `shutdown()` per logical request/job. The singleton API remains PHP-FPM-friendly, but request context is explicitly refreshed to avoid cross-request trace leakage.
- Official SDK adapter is deferred until target apps can run a compatible PHP version.
