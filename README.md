# Elven OpenTelemetry Instrumentation PHP Legacy

OpenTelemetry-compatible traces, metrics, and log correlation for legacy PHP apps.

This package is built for PHP applications that cannot safely use the official OpenTelemetry PHP SDK or PECL extension yet. It supports `php >=7.3.13`, exports OTLP HTTP/JSON directly to an OpenTelemetry Collector, and never lets telemetry failures break the application.

## What You Get By Default

- Traces exported to `http://localhost:4318/v1/traces`.
- Metrics exported to `http://localhost:4318/v1/metrics`.
- W3C `traceparent` and `tracestate` extraction/injection.
- W3C `baggage` extraction/injection with sensitive-key filtering.
- Parent-based sampling with ratio `1`.
- Request-local span limit of `128`.
- Export timeout of `200ms`.
- Monolog 1 log correlation enabled.
- OTLP logs export disabled; logs stay in your existing pipeline.
- Payload, XML/body, raw user id, cookies, tokens, passwords, Authorization, and raw SQL capture disabled.
- DB spans include `db.query.summary` by default. Raw `db.statement` is redacted unless you explicitly opt in.

## Install

### Packagist

After the package is registered on Packagist:

```bash
composer require elven-observability/opentelemetry-instrumentation-php-legacy
```

### Public GitHub VCS

This works immediately while Packagist registration is pending and does not require a GitHub token because the repository is public:

```bash
composer config repositories.elven-php-legacy vcs https://github.com/elven-observability/opentelemetry-instrumentation-php-legacy
composer require elven-observability/opentelemetry-instrumentation-php-legacy:^0.1
```

For committed application configuration:

```json
{
  "repositories": [
    {
      "type": "vcs",
      "url": "https://github.com/elven-observability/opentelemetry-instrumentation-php-legacy"
    }
  ],
  "require": {
    "elven-observability/opentelemetry-instrumentation-php-legacy": "^0.1"
  }
}
```

Remove the `repositories` block after the package is available on Packagist.

The library requires only PHP and `ext-json`. cURL, SOAP, Guzzle, Slim 2, and Monolog are optional integrations.

## 5 Minute Setup

Set the service identity and point the app at an OpenTelemetry Collector:

```bash
ELVEN_OTEL_ENABLED=true
OTEL_SERVICE_NAME=legacy-booking-api
OTEL_SERVICE_NAMESPACE=booking
ELVEN_ENVIRONMENT=staging
OTEL_EXPORTER_OTLP_ENDPOINT=http://otel-collector:4318
```

Then initialize once near the start of the request, usually right after Composer autoload:

```php
<?php

use Elven\Observability\PhpLegacy\Observability;

require_once __DIR__ . '/vendor/autoload.php';

$handle = Observability::init();

register_shutdown_function(function () use ($handle) {
    $handle->shutdown();
});
```

That is enough for manual spans, metrics, propagation helpers, and log correlation. The app does not need an Elven token when it sends telemetry to a local or customer-owned Collector; backend credentials belong in the Collector config.

## Manual Spans

```php
use Elven\Observability\PhpLegacy\Observability;

Observability::tracer()->withSpan('ticket.search', function ($span) {
    $span->setAttribute('operation', 'ticket_search');
    $span->setAttribute('product', 'air');

    // Run the real work here.
});
```

Exceptions are recorded as sanitized exception events and the span status becomes `ERROR`.

## HTTP Server Span

For Slim 2 or custom routing, wrap the route handler with a stable route name. Do not use request ids, order ids, user ids, tokens, CPF, email, or trace ids as route names or metric labels.

```php
use Elven\Observability\PhpLegacy\Instrumentation\HttpServerInstrumentation;

$route = sprintf(
    '%s /rest/v%s/%s/%s',
    $_SERVER['REQUEST_METHOD'],
    $version,
    $controller,
    $action
);

$result = HttpServerInstrumentation::instrument($route, function () use ($serviceObject, $method, $requestData) {
    return $serviceObject->$method($requestData);
});
```

The span extracts upstream `traceparent`/`tracestate` from `$_SERVER`, names the operation with the stable route, records HTTP status, and emits `http.server.request.duration`.

## Outbound HTTP Propagation

Use the header helpers inside the client span so the downstream request receives the correct child context.

```php
use Elven\Observability\PhpLegacy\Instrumentation\HeaderInjector;
use Elven\Observability\PhpLegacy\Instrumentation\HttpClientInstrumentation;

$response = HttpClientInstrumentation::instrument(
    'POST',
    'https://dsg.example.test/booking',
    function ($span, array $headers) {
        // Pass $headers to cURL, Guzzle, SOAP/WCF, or your internal wrapper.
        // Example cURL format:
        // curl_setopt($ch, CURLOPT_HTTPHEADER, HeaderInjector::toHeaderLines($headers));
    }
);
```

The Guzzle integration is a normal middleware:

```php
use Elven\Observability\PhpLegacy\Instrumentation\GuzzleInstrumentation;
use GuzzleHttp\HandlerStack;

$stack = HandlerStack::create();
$stack->push(GuzzleInstrumentation::middleware());
```

## DB Spans And SQL Statements

By default, DB spans are useful but privacy-safe:

- `db.system`, `db.name`, `db.operation.name`.
- `db.query.summary`, for example `SELECT users`.
- `db.statement` is present only as redacted telemetry unless you opt in.

```php
use Elven\Observability\PhpLegacy\Instrumentation\DbInstrumentation;

$rows = DbInstrumentation::traceQuery(
    'mysql',
    'select',
    'booking',
    function () use ($pdo) {
        return $pdo->query('select * from tickets where email = "person@example.test"');
    },
    'select * from tickets where email = "person@example.test"'
);
```

To capture sanitized SQL text during a controlled investigation:

```bash
ELVEN_OTEL_CAPTURE_DB_STATEMENT=true
ELVEN_OTEL_REDACT_DB_STATEMENT=true
```

Do not set `ELVEN_OTEL_REDACT_DB_STATEMENT=false` in production unless a written privacy exception exists.

## Business Metrics

```php
Observability::metrics()->counter('booking.ticket.search.started')->add(1, array(
    'operation' => 'ticket_search',
));
```

Only low-cardinality labels are exported: `service_name`, `service_namespace`, `environment`, `route`, `method`, `status_code`, `dependency_type`, `dependency_name`, `operation`, and `error_type`.

## Log Correlation

For Monolog 1:

```php
use Elven\Observability\PhpLegacy\Logs\MonologTraceProcessor;

$logger->pushProcessor(new MonologTraceProcessor());
```

For custom log wrappers:

```php
$context = Observability::logs()->correlate($context);
```

Every correlated log receives `trace_id`, `span_id`, `trace_flags`, `service_name`, `environment`, and `hostname`. Logs are not exported by this library.

## Important Environment Variables

```bash
ELVEN_OTEL_ENABLED=true
OTEL_SERVICE_NAME=legacy-booking-api
OTEL_SERVICE_NAMESPACE=booking
OTEL_SERVICE_VERSION=1.0.0
ELVEN_ENVIRONMENT=staging
OTEL_EXPORTER_OTLP_ENDPOINT=http://otel-collector:4318
OTEL_EXPORTER_OTLP_PROTOCOL=http/json
OTEL_PROPAGATORS=tracecontext,baggage
OTEL_TRACES_SAMPLER=parentbased_traceidratio
OTEL_TRACES_SAMPLER_ARG=1
OTEL_METRICS_EXPORTER=otlp
OTEL_LOGS_EXPORTER=none
ELVEN_OTEL_LOG_CORRELATION_ENABLED=true
ELVEN_OTEL_CAPTURE_DB_STATEMENT=false
ELVEN_OTEL_REDACT_DB_STATEMENT=true
ELVEN_OTEL_MAX_SPANS_PER_REQUEST=128
ELVEN_OTEL_MAX_METRIC_POINTS_PER_REQUEST=512
ELVEN_OTEL_EXPORT_TIMEOUT_MS=200
```

Set `ELVEN_OTEL_ENABLED=false` for the total kill switch. It wins even if code calls `Observability::init(array('enabled' => true))`.

## NGINX And PHP-FPM Context Forwarding

If NGINX terminates the request before PHP-FPM, forward trace headers:

```nginx
fastcgi_param HTTP_TRACEPARENT $http_traceparent;
fastcgi_param HTTP_TRACESTATE  $http_tracestate;
fastcgi_param HTTP_BAGGAGE     $http_baggage;
```

## Local Validation

This host does not need local PHP or Composer. Use Docker:

```bash
docker compose run --rm php73 composer validate --strict
docker compose run --rm php73 composer check
docker compose run --rm php74 composer check
```

Run the fake Collector:

```bash
docker compose up fake-collector
```

Then point an example or app at:

```bash
OTEL_EXPORTER_OTLP_ENDPOINT=http://127.0.0.1:4318
```

## Production Rollout

1. Enable in staging with the target Collector.
2. Validate traces in Tempo, metrics in Mimir/Prometheus, and correlated logs in Loki.
3. Canary one PHP-FPM replica or one small instance pool.
4. Watch error rate, p95 latency, exporter failures, and dropped spans.
5. Expand gradually.
6. Do not restart production outside a controlled window.

## Documentation

- `docs/SDD.md`
- `docs/env-vars.md`
- `docs/legacy-slim2-integration.md`
- `docs/privacy.md`
- `docs/performance.md`
- `docs/troubleshooting.md`
