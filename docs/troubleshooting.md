# Troubleshooting

## No telemetry arrives

1. Confirm `ELVEN_OTEL_ENABLED=true`.
2. Confirm `OTEL_EXPORTER_OTLP_PROTOCOL=http/json`.
3. Confirm `OTEL_EXPORTER_OTLP_ENDPOINT` reaches the Collector from the PHP container.
4. Temporarily set `OTEL_TRACES_EXPORTER=none` to isolate app behavior from export behavior.
5. Use the fake collector:

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

## `http/protobuf` configured

This v1 library supports `http/json`. If `http/protobuf` is configured, telemetry is disabled safely. Change:

```bash
OTEL_EXPORTER_OTLP_PROTOCOL=http/json
```

## Composer platform failures

The package targets `php >=7.3.13` and avoids PHP 7.4+ syntax. Run the Docker PHP 7.3 check:

```bash
docker compose run --rm php73 composer check
```

## Collector credentials

When exporting to a local/customer Collector, do not put Elven tokens in the PHP app. Configure credentials in the Collector exporter that forwards data to Elven Observability.
