# Troubleshooting

## No telemetry arrives

1. Confirm `ELVEN_OTEL_ENABLED=true`.
2. Confirm `OTEL_EXPORTER_OTLP_PROTOCOL=http/json`.
3. Confirm `OTEL_EXPORTER_OTLP_ENDPOINT` reaches the Collector from the PHP container.
4. Prove `getenv()` through an HTTP/FPM request, not only `docker exec ... printenv` or CLI.
5. Confirm signal-specific endpoint/protocol env vars are not overriding the base with an unsupported value.
6. Temporarily set `OTEL_TRACES_EXPORTER=none` to isolate app behavior from export behavior.
7. Use the fake collector:

```bash
docker compose up fake-collector
```

## Trace IDs missing from logs

Make sure logs are emitted inside an active span or server route wrapper, then add:

```php
$logger->pushProcessor(new \Elven\Observability\PhpLegacy\Logs\MonologTraceProcessor());
```

For custom wrappers:

```php
$context = \Elven\Observability\PhpLegacy\Observability::logs()->correlate($context);
```

Correlation is not automatic for arbitrary logger instances. Add the processor/facade at the shared logger factory.

## OTLP logs do not appear in Loki

Confirm the app is actually exporting logs:

```bash
OTEL_LOGS_EXPORTER=otlp
OTEL_EXPORTER_OTLP_ENDPOINT=http://otel-collector:4318
```

For Monolog 1/2, correlation alone is not the exporter. Add the OTLP handler:

```php
$logger->pushProcessor(new \Elven\Observability\PhpLegacy\Logs\MonologTraceProcessor());
$logger->pushHandler(new \Elven\Observability\PhpLegacy\Logs\MonologOtlpHandler());
```

The PHP app sends to Collector `/v1/logs`, not directly to Loki. Check the Collector logs pipeline and Loki exporter if the fake collector receives `/v1/logs` but Loki has no entries.

If logs are duplicated, you probably have both OTLP log export and file/stdout scraping enabled. Keep one path: set `OTEL_LOGS_EXPORTER=none` to keep only correlation in the existing pipeline.

## Traffic source labels missing from metrics

Set request attributes before dependency/business metrics are emitted:

```php
$traffic = \Elven\Observability\PhpLegacy\Attribution\TrafficSourceResolver::attributesFromRequest($requestData, $_SERVER);
\Elven\Observability\PhpLegacy\Observability::metrics()->setRequestAttributes($traffic);
$span->setAttributes($traffic);
```

If the app passes only dynamic ids such as click id, redirect id, session id, or order id, the resolver will intentionally export `unknown`/`other` instead of the raw value. Map the app-specific source to stable categories such as `front`, `skyscanner`, `google_flights`, `mundi`, `kayak`, `viajala`, or `backend`.

## Only gauge metrics appear in Mimir

If the Collector accepts `/v1/metrics` but Mimir shows gauges such as `elven_php_request_memory_peak` while counters/histograms such as `http_server_request_duration_*` or `elven_php_dependency_duration_*` are missing, check the Collector self-metric:

```promql
increase(otelcol_exporter_prometheusremotewrite_failed_translations[10m])
```

For Collector pipelines that forward metrics to Mimir with `prometheusremotewrite`, keep:

```bash
ELVEN_OTEL_METRICS_TEMPORALITY=cumulative
```

Use `delta` only when the Collector has an explicit delta-compatible processor/exporter path.

## Values are still redacted

Library-side redaction is enabled by default. To disable it globally for customers that explicitly own redaction downstream:

```bash
ELVEN_OTEL_REDACTION_ENABLED=false
```

Also accepted: `off`, `0`, and `no`. Restart/reload PHP-FPM after changing environment variables so workers see the new value.

This switch keeps span/log/header values raw. DB statements still require `ELVEN_OTEL_CAPTURE_DB_STATEMENT=true`; otherwise `DbInstrumentation::traceQuery()` exports only `db.query.summary`. It does not disable metric label allowlists, metric label normalization, or high-cardinality collapse.

## `http/protobuf` configured

This release supports `http/json`. An unsupported base protocol disables inherited signal exporters; a signal-specific unsupported protocol disables only that signal. Change the relevant env:

```bash
OTEL_EXPORTER_OTLP_PROTOCOL=http/json
```

Also check `OTEL_EXPORTER_OTLP_TRACES_PROTOCOL`, `OTEL_EXPORTER_OTLP_METRICS_PROTOCOL`, and `OTEL_EXPORTER_OTLP_LOGS_PROTOCOL`.

## CLI works but HTTP/FPM does not

The container has env vars, but FPM likely cleared them. Add explicit `env[NAME] = $NAME` entries to the pool config in the image, rebuild, and verify through a temporary HTTP endpoint. Remove the diagnostic endpoint after proof.

## Request span is missing when response helper calls `exit`

Use `FrontControllerInstrumentation::beginFromGlobals()` or `HttpServerInstrumentation::begin()`. A plain callback wrapper cannot run its `finally` when legacy code terminates the request. The request scope uses the library shutdown registry and must start before dispatch.

## AWS request has traceparent but propagation/signature behavior is uncertain

Use `AwsInstrumentation::register($client, 'service')`, not a custom build-stage registration. The helper registers at the tested pre-SigV4 position and is idempotent. In a synthetic request, `Authorization` `SignedHeaders` should include `traceparent`.

## Large latency only when Collector is unavailable

Keep the Collector local and the export timeout near `200ms`. Three enabled signals flush sequentially, so large timeouts multiply request-shutdown delay before circuit breakers open. Use the kill switch during an incident.

## Composer platform failures

The package targets `php >=7.3.13` and avoids PHP 7.4+ syntax. Run the Docker PHP 7.3 check:

```bash
docker compose run --rm php73 composer check
```

## Collector credentials

When exporting to a local/customer Collector, do not put Elven tokens in the PHP app. Configure credentials in the Collector exporter that forwards data to Elven Observability.
