# Elven OpenTelemetry Instrumentation PHP Legacy

Production-oriented OpenTelemetry-compatible traces, metrics, OTLP logs, and log correlation for legacy PHP applications.

This library is for PHP apps that cannot safely use the official OpenTelemetry PHP SDK or the PECL `opentelemetry` extension yet. It supports `php >=7.3.13`, exports OTLP HTTP/JSON directly to an OpenTelemetry Collector, propagates W3C trace context, and never lets telemetry failures break the application.

## What This Library Does

- Creates PHP internal spans for legacy/custom apps.
- Continues upstream traces through W3C `traceparent`, `tracestate`, and `baggage`.
- Injects trace headers into outbound HTTP, cURL, Guzzle, SOAP/WCF, and custom wrappers.
- Exports traces to `/v1/traces`, metrics to `/v1/metrics`, and logs to `/v1/logs` over OTLP HTTP/JSON.
- Adds `trace_id` and `span_id` to Monolog 1 or custom log contexts.
- Can send Monolog/custom application logs to the OpenTelemetry Collector so the Collector can route them to Loki.
- Emits low-cardinality request, dependency, exporter, and business metrics.
- Adds bounded traffic attribution labels, such as `traffic_source=skyscanner` and `traffic_channel=metasearch`.
- Redacts sensitive attributes by default.
- Drops excessive spans, metric points, and log records per request instead of growing unbounded memory.

## Safe Defaults

Default behavior is intentionally conservative:

- `ELVEN_OTEL_ENABLED=true`.
- OTLP endpoint base: `http://localhost:4318`.
- Protocol: `http/json`.
- Export timeout: `200ms`.
- Sampler: `parentbased_traceidratio` with ratio `1`.
- Max spans per request: `128`.
- Max metric points per request: `512`.
- Max OTLP log records per request: `512`.
- Monolog/custom log correlation: enabled.
- OTLP logs exporter: disabled unless `OTEL_LOGS_EXPORTER=otlp` is set, to avoid accidentally duplicating an existing log pipeline.
- Payload, body, XML, cookies, tokens, passwords, Authorization, raw user id, and raw SQL capture: disabled.
- DB spans include `db.query.summary`; raw `db.statement` is redacted unless explicitly enabled.

Set this at any time to turn the whole library into no-op:

```bash
ELVEN_OTEL_ENABLED=false
```

The kill switch wins even if application code calls `Observability::init(array('enabled' => true))`.

## Requirements

- PHP `>=7.3.13`.
- Composer.
- `ext-json`.
- An OpenTelemetry Collector reachable by the PHP app.

Optional integrations:

- `ext-curl` for lower-overhead OTLP export and cURL examples.
- `ext-soap` for SOAP/WCF examples.
- `guzzlehttp/guzzle` for Guzzle middleware.
- `monolog/monolog` for Monolog 1 log correlation and OTLP log export handler.
- `slim/slim` for Slim 2 examples.

## Install

### Current Public GitHub Install

Use this while Packagist registration is pending. No GitHub token is required because the repository is public.

```bash
composer config repositories.elven-php-legacy vcs https://github.com/elven-observability/opentelemetry-instrumentation-php-legacy
composer require elven-observability/opentelemetry-instrumentation-php-legacy:^0.4
```

For application repos, commit this in `composer.json`:

```json
{
  "repositories": [
    {
      "type": "vcs",
      "url": "https://github.com/elven-observability/opentelemetry-instrumentation-php-legacy"
    }
  ],
  "require": {
    "elven-observability/opentelemetry-instrumentation-php-legacy": "^0.4"
  }
}
```

For apps pinned to PHP `7.3.13`, keep the app's existing Composer platform:

```json
{
  "config": {
    "platform": {
      "php": "7.3.13"
    }
  }
}
```

### Future Packagist Install

After the package is registered on Packagist, the app can use only:

```bash
composer require elven-observability/opentelemetry-instrumentation-php-legacy:^0.4
```

Then remove the temporary `repositories` block from `composer.json`.

## Step 1: Configure Environment

Start in staging. Replace the service values and Collector host.

```bash
ELVEN_OTEL_ENABLED=true

OTEL_SERVICE_NAME=legacy-booking-api
OTEL_SERVICE_NAMESPACE=booking
OTEL_SERVICE_VERSION=1.0.0
ELVEN_ENVIRONMENT=staging

OTEL_EXPORTER_OTLP_ENDPOINT=http://otel-collector:4318
OTEL_EXPORTER_OTLP_PROTOCOL=http/json
OTEL_EXPORTER_OTLP_TIMEOUT=200
ELVEN_OTEL_EXPORT_TIMEOUT_MS=200

OTEL_PROPAGATORS=tracecontext,baggage
OTEL_TRACES_EXPORTER=otlp
OTEL_TRACES_SAMPLER=parentbased_traceidratio
OTEL_TRACES_SAMPLER_ARG=1

OTEL_METRICS_EXPORTER=otlp
OTEL_LOGS_EXPORTER=otlp
ELVEN_OTEL_LOG_CORRELATION_ENABLED=true

ELVEN_OTEL_CAPTURE_DB_STATEMENT=false
ELVEN_OTEL_REDACT_DB_STATEMENT=true
ELVEN_OTEL_ALLOW_RAW_ATTRIBUTES=

ELVEN_OTEL_MAX_SPANS_PER_REQUEST=128
ELVEN_OTEL_MAX_METRIC_POINTS_PER_REQUEST=512
ELVEN_OTEL_MAX_LOG_RECORDS_PER_REQUEST=512
ELVEN_OTEL_DEBUG=false
```

The PHP app does not need an Elven token when it sends telemetry to a customer-owned or local Collector. Backend credentials belong in the Collector config.

If logs are already collected from files/stdout and you do not want duplicate Loki entries, use `OTEL_LOGS_EXPORTER=none` and keep only the log correlation processor.

Minimal Collector logs pipeline for Loki native OTLP ingestion:

```yaml
receivers:
  otlp:
    protocols:
      http:
        endpoint: 0.0.0.0:4318

processors:
  batch: {}

exporters:
  otlphttp/loki:
    endpoint: http://loki:3100/otlp

service:
  pipelines:
    logs:
      receivers: [otlp]
      processors: [batch]
      exporters: [otlphttp/loki]
```

For Loki deployments that do not already enable structured metadata, set `limits_config.allow_structured_metadata=true` in Loki. Keep tenant/auth configuration in the Collector, not in PHP.

## Step 2: Forward Trace Headers Through NGINX/PHP-FPM

If NGINX forwards requests to PHP-FPM, preserve incoming W3C trace context:

```nginx
fastcgi_param HTTP_TRACEPARENT $http_traceparent;
fastcgi_param HTTP_TRACESTATE  $http_tracestate;
fastcgi_param HTTP_BAGGAGE     $http_baggage;
```

Without these params, PHP starts a new trace instead of continuing the upstream trace.

## Step 3: Initialize Once Per Request

Initialize near the start of the request, usually right after Composer autoload/bootstrap.

```php
<?php

use Elven\Observability\PhpLegacy\Observability;

require_once __DIR__ . '/../vendor/autoload.php';

$otelHandle = Observability::init();

register_shutdown_function(function () use ($otelHandle) {
    $otelHandle->shutdown();
});
```

`shutdown()` is idempotent and exception-safe. It flushes request-local spans and metrics without throwing into application code.

## Step 4: Wrap The Main HTTP Route

For Slim 2 or custom front controllers, wrap the central route/controller invocation. Use a stable route name. Never put request ids, order ids, user ids, tokens, CPF, email, or trace ids in span names or metric labels.

```php
use Elven\Observability\PhpLegacy\Bridge\Legacy\RestRouteInstrumentation;

$result = RestRouteInstrumentation::traceRestAction(
    $version,
    $controller,
    $action,
    function ($span) use ($serviceObject, $method, $requestData, $controller, $action) {
        $span->setAttribute('operation', strtolower($controller . '.' . $action));
        return $serviceObject->$method($requestData);
    }
);
```

For non-REST routing, use the lower-level helper:

```php
use Elven\Observability\PhpLegacy\Instrumentation\HttpServerInstrumentation;

$route = 'POST /api/v1/tickets/search';

$result = HttpServerInstrumentation::instrument($route, function ($span) use ($handler, $request) {
    $span->setAttribute('operation', 'ticket_search');
    return $handler->handle($request);
});
```

Server spans automatically include safe HTTP attributes such as method, route, sanitized path, server address, response status, service name, environment, and host name.

## Step 4.1: Attribute Traffic Source

`HttpServerInstrumentation` automatically derives baseline labels from safe globals such as `utm_source`, query string, and referrer. If the app receives traffic from owned frontend, metasearch, paid search, partners, or backoffice flows and the real source is available in the parsed request data, resolve it once near the server span:

```php
use Elven\Observability\PhpLegacy\Attribution\TrafficSourceResolver;
use Elven\Observability\PhpLegacy\Observability;

$traffic = TrafficSourceResolver::attributesFromRequest($requestData, $_SERVER);

Observability::metrics()->setRequestAttributes($traffic);
$span->setAttributes($traffic);
```

Metrics emitted after `setRequestAttributes()` automatically include:

- `traffic_source`, for example `front`, `skyscanner`, `google_flights`, `mundi`, `kayak`, `viajala`, `partner_offers`, `backend`, `other`, `unknown`
- `traffic_channel`, for example `owned`, `metasearch`, `paid`, `partner`, `backoffice`, `unknown`

Never use click ids, redirect ids, campaigns, order ids, session ids, full referrers, or raw partner values as metric labels. The resolver collapses unknown/high-cardinality-looking values to `other` or `unknown`.

## Step 5: Propagate Context To Outbound HTTP

Create the client span before sending the request. The callback receives headers that already contain the child `traceparent`.

```php
use Elven\Observability\PhpLegacy\Instrumentation\HeaderInjector;
use Elven\Observability\PhpLegacy\Instrumentation\HttpClientInstrumentation;

$response = HttpClientInstrumentation::instrument('POST', $url, function ($span, array $headers) use ($ch) {
    curl_setopt($ch, CURLOPT_HTTPHEADER, HeaderInjector::toHeaderLines($headers));

    $body = curl_exec($ch);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    return array(
        'status_code' => $statusCode,
        'body' => $body,
    );
});
```

Do not attach request or response bodies to spans.

For Guzzle:

```php
use Elven\Observability\PhpLegacy\Instrumentation\GuzzleInstrumentation;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;

$stack = HandlerStack::create();
$stack->push(GuzzleInstrumentation::middleware());

$client = new Client(array('handler' => $stack));
```

For SOAP/WCF/custom XML transports:

```php
use Elven\Observability\PhpLegacy\Instrumentation\SoapInstrumentation;

$response = SoapInstrumentation::instrument(
    'BookingService',
    $action,
    $serverAddress,
    $timeout,
    function ($span) use ($client, $request) {
        $headers = SoapInstrumentation::injectHttpHeaders(array(), $span);
        return $client->execute($request, $headers);
    }
);
```

SOAP/XML payload capture is off by default.

## Step 6: Wrap DB Calls Safely

Use `DbInstrumentation::traceQuery()` around PDO, mysqli, Medoo, or custom DB wrapper calls.

```php
use Elven\Observability\PhpLegacy\Instrumentation\DbInstrumentation;

$rows = DbInstrumentation::traceQuery(
    'mysql',
    'select',
    'booking',
    function () use ($pdo, $sql) {
        return $pdo->query($sql);
    },
    $sql
);
```

Default DB telemetry:

- `db.system`
- `db.name`
- `db.operation.name`
- `db.query.summary`, for example `SELECT tickets`
- redacted `db.statement`

For controlled troubleshooting with sanitized SQL:

```bash
ELVEN_OTEL_CAPTURE_DB_STATEMENT=true
ELVEN_OTEL_REDACT_DB_STATEMENT=true
```

Do not use this in production without explicit written approval:

```bash
ELVEN_OTEL_REDACT_DB_STATEMENT=false
```

## Step 7: Add Logs For Loki Via Collector

This library does not send directly to Loki. It sends OTLP logs to the OpenTelemetry Collector at `/v1/logs`; the Collector should route those logs to Loki. This keeps Loki credentials and backend routing out of the PHP app.

For Monolog 1 correlation plus OTLP log export:

```php
use Elven\Observability\PhpLegacy\Logs\MonologOtlpHandler;
use Elven\Observability\PhpLegacy\Logs\MonologTraceProcessor;

$logger->pushProcessor(new MonologTraceProcessor());
$logger->pushHandler(new MonologOtlpHandler());
```

For custom log wrappers that still write to the existing log pipeline:

```php
use Elven\Observability\PhpLegacy\Observability;

$context = Observability::logs()->correlate($context);
$logger->info('ticket search started', $context);
```

For custom log wrappers that should also emit OTLP logs:

```php
use Elven\Observability\PhpLegacy\Observability;

Observability::logs()->emit('INFO', 'ticket search started', array(
    'operation' => 'ticket_search',
));
```

Every correlated log receives:

- `trace_id`
- `span_id`
- `trace_flags`
- `service_name`
- `environment`
- `hostname`

The log body and attributes are redacted and bounded before export. Do not put request/response payloads, XML, tokens, cookies, passwords, CPF, email, card data, or raw customer identifiers in log messages.

## Step 8: Add Business Metrics

Use stable metric names and low-cardinality labels.

```php
use Elven\Observability\PhpLegacy\Observability;

Observability::metrics()->counter('booking.ticket.search.started')->add(1, array(
    'operation' => 'ticket_search',
));
```

Allowed metric labels:

- `service_name`
- `service_namespace`
- `environment`
- `route`
- `method`
- `status_code`
- `dependency_type`
- `dependency_name`
- `operation`
- `error_type`
- `traffic_source`
- `traffic_channel`

Never use request id, order id, user id, token, CPF, email, trace id, full URL, SQL statement, or object key as a metric label.

## Step 9: Validate Locally

This repository includes Docker-based validation, so the host does not need local PHP or Composer.

```bash
docker compose run --rm php73 composer validate --strict
docker compose run --rm php73 composer check
docker compose run --rm php74 composer check
```

Run the fake Collector:

```bash
docker compose up fake-collector
```

Point the app or examples at it:

```bash
OTEL_EXPORTER_OTLP_ENDPOINT=http://127.0.0.1:4318
```

Expected fake Collector paths:

- `/v1/traces`
- `/v1/metrics`
- `/v1/logs`

## Step 10: Validate In Staging

Before canary:

- Composer install works with the app's PHP platform, including `7.3.13`.
- `ELVEN_OTEL_ENABLED=false` makes telemetry no-op.
- Incoming `traceparent` appears as the parent of PHP server spans.
- Outbound HTTP/SOAP/WCF calls receive child `traceparent` headers.
- Error responses and exceptions mark spans as `ERROR`.
- Logs contain `trace_id` and `span_id`.
- If `OTEL_LOGS_EXPORTER=otlp`, the Collector receives `/v1/logs` and Loki receives the routed entries.
- DB spans contain `db.query.summary`.
- Raw SQL remains redacted unless controlled troubleshooting envs are enabled.
- Fake JWT, bearer token, cookie, CPF, email, card, and SQL literals are redacted.
- Tempo receives traces.
- Mimir/Prometheus receives metrics.
- Loki logs can be correlated by `trace_id`.
- The PHP app has no backend/vendor token in its environment.

## Production Rollout

1. Enable in staging.
2. Canary one PHP-FPM replica or one small instance pool.
3. Validate Tempo, Mimir/Prometheus, and Loki.
4. Watch HTTP 5xx, p95/p99 latency, PHP-FPM saturation, `elven.php.exporter.failed_exports`, `elven.php.exporter.dropped_spans`, and `elven.php.exporter.dropped_metric_points`.
5. Expand gradually.
6. Keep `ELVEN_OTEL_ENABLED=false` ready.
7. Do not restart production outside a controlled window.

## Troubleshooting

No traces:

- Confirm `ELVEN_OTEL_ENABLED=true`.
- Confirm `OTEL_EXPORTER_OTLP_PROTOCOL=http/json`.
- Confirm `OTEL_EXPORTER_OTLP_ENDPOINT` reaches the Collector from the PHP container/host.
- Confirm the Collector accepts `/v1/traces`.
- Check `elven.php.exporter.failed_exports`.

Trace does not continue upstream:

- Confirm NGINX forwards `HTTP_TRACEPARENT`, `HTTP_TRACESTATE`, and `HTTP_BAGGAGE`.
- Confirm the header reaches `$_SERVER['HTTP_TRACEPARENT']`.
- Confirm the PHP server span has the same `trace_id` as the upstream span.

High metric cardinality:

- Remove labels outside the allowlist.
- Replace dynamic paths with stable routes.
- Remove ids, emails, CPFs, tokens, trace ids, SQL, and object keys from labels.

Collector unavailable:

- The app should continue normally.
- Exporter failures are caught.
- The timeout is short.
- Circuit breaker reduces repeated attempts.
- Use `ELVEN_OTEL_ENABLED=false` if telemetry creates operational risk.

## Implementation Checklist For Engineers Or Coding Agents

1. Add the Composer dependency.
2. Add env vars from Step 1.
3. Add NGINX/PHP-FPM trace header forwarding if applicable.
4. Call `Observability::init()` once near request start.
5. Register `$otelHandle->shutdown()` with `register_shutdown_function()`.
6. Wrap the central route/controller invocation with a stable server span.
7. Set traffic attribution with `TrafficSourceResolver` and `Observability::metrics()->setRequestAttributes(...)`.
8. Wrap outbound HTTP, cURL, Guzzle, SOAP/WCF, DB, cache, queue, mail, storage, and search calls where useful.
9. Add Monolog 1 processor plus `MonologOtlpHandler`, or custom `Observability::logs()->correlate($context)` / `Observability::logs()->emit(...)`.
10. Add only low-cardinality business metrics.
11. Validate staging before canary.

Do not capture request/response bodies, XML payloads, raw SQL, tokens, cookies, Authorization headers, CPF/email/card values, raw user ids, click ids, redirect ids, campaigns, or dynamic ids in metric labels.

## More Documentation

- `docs/SDD.md`
- `docs/env-vars.md`
- `docs/legacy-slim2-integration.md`
- `docs/privacy.md`
- `docs/performance.md`
- `docs/traffic-attribution.md`
- `docs/troubleshooting.md`
