# Legacy Slim 2 Integration Guide

This guide shows how to integrate the library into a legacy PHP app that uses Slim 2 or a custom front controller with routes like `/rest/:version/:controller/:action`.

## Composer

Keep the target application's existing Composer platform constraint. This library supports `php >=7.3.13`.

```bash
composer require elven-observability/opentelemetry-instrumentation-php-legacy:^0.5
```

## Environment

Start with staging and a local or customer-owned OpenTelemetry Collector:

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
OTEL_LOGS_EXPORTER=otlp
ELVEN_OTEL_LOG_CORRELATION_ENABLED=true
ELVEN_OTEL_REDACTION_ENABLED=true
ELVEN_OTEL_CAPTURE_DB_STATEMENT=false
ELVEN_OTEL_REDACT_DB_STATEMENT=true
ELVEN_OTEL_MAX_SPANS_PER_REQUEST=128
ELVEN_OTEL_MAX_LOG_RECORDS_PER_REQUEST=512
ELVEN_OTEL_EXPORT_TIMEOUT_MS=200
```

## Front Controller

Initialize after Composer autoload and register shutdown flushing:

```php
<?php

use Elven\Observability\PhpLegacy\Observability;

require_once __DIR__ . '/../vendor/autoload.php';

$handle = Observability::init(array(
    'service_name' => getenv('OTEL_SERVICE_NAME') ?: 'legacy-booking-api',
    'service_namespace' => getenv('OTEL_SERVICE_NAMESPACE') ?: 'booking',
    'environment' => getenv('ELVEN_ENVIRONMENT') ?: 'staging',
));

register_shutdown_function(function () use ($handle) {
    $handle->shutdown();
});
```

## Stable Route Span

Wrap the real controller/action invocation and use a stable route name. Do not include request ids, order ids, user ids, CPF, email, tokens, or trace ids in the span name or metric labels.

```php
use Elven\Observability\PhpLegacy\Attribution\TrafficSourceResolver;
use Elven\Observability\PhpLegacy\Bridge\Legacy\RestRouteInstrumentation;
use Elven\Observability\PhpLegacy\Observability;

$result = RestRouteInstrumentation::traceRestAction(
    $version,
    $controller,
    $action,
    function ($span) use ($serviceObject, $method, $requestData, $controller, $action) {
        $traffic = TrafficSourceResolver::attributesFromRequest($requestData, $_SERVER);
        Observability::metrics()->setRequestAttributes($traffic);
        $span->setAttributes($traffic);
        $span->setAttribute('operation', strtolower($controller . '.' . $action));
        return $serviceObject->$method($requestData);
    }
);
```

The resulting route is `/rest/v{version}/{controller}/{action}` and the server span extracts inbound `traceparent`/`tracestate` automatically from `$_SERVER`.

Traffic labels are inherited by all metrics emitted after `setRequestAttributes()`, including server duration, dependency duration, exporter metrics, and business counters.

### Recording the real HTTP status (Slim 2)

Slim 2 sets the status on its `Response` object and only flushes it (via `header()`) during `$app->run()` / `Response::finalize()`, which runs **after** the route span closes. At that point `http_response_code()` is still `200`, so by default the SERVER span and the `http.server.request.duration` metric would record `200` even for `401`/`400`/`5xx` responses.

Pass an optional status resolver as the 5th argument so the span reads the real status when it closes:

```php
$result = RestRouteInstrumentation::traceRestAction(
    $version,
    $controller,
    $action,
    function ($span) use ($serviceObject, $method, $requestData) {
        return $serviceObject->$method($requestData);
    },
    function () use ($app) {
        return $app->response->getStatus();
    }
);
```

The resolver runs when the span closes and must return a valid HTTP status code (100-599). Invalid/falsy results and resolver exceptions fall back to `http_response_code()`, so telemetry never breaks the request. Slim re-applies the same status on `finalize()`, so the HTTP response itself is unchanged.

If you cannot pass a resolver, an equivalent workaround is to mirror the status into the global before the span closes, inside the closure after the handler returns:

```php
$result = $serviceObject->$method($requestData);
$status = $app->response->getStatus();
if (is_int($status) && $status > 0 && !headers_sent()) {
    http_response_code($status);
}
return $result;
```

## Downstream HTTP, SOAP, WCF, Or Internal Wrappers

Create the client span before injecting headers. This ensures the outbound `traceparent` uses the child span id, not the parent request span id.

```php
use Elven\Observability\PhpLegacy\Instrumentation\HeaderInjector;
use Elven\Observability\PhpLegacy\Instrumentation\HttpClientInstrumentation;

$response = HttpClientInstrumentation::instrument('POST', $url, function ($span, array $headers) use ($ch) {
    curl_setopt($ch, CURLOPT_HTTPHEADER, HeaderInjector::toHeaderLines($headers));
    return curl_exec($ch);
});
```

For SOAP/WCF wrappers, inject the same headers into the HTTP transport when possible:

```php
use Elven\Observability\PhpLegacy\Instrumentation\SoapInstrumentation;

$result = SoapInstrumentation::instrument($service, $method, $host, $timeout, function ($span) use ($client) {
    $headers = SoapInstrumentation::injectHttpHeaders(array(), $span);
    // Pass $headers to the underlying cURL/WCF transport.
    return $client->call();
});
```

No XML/body/payload is captured by default.

## DB Statements

By default the library emits a safe `db.query.summary` and redacts raw SQL:

```php
use Elven\Observability\PhpLegacy\Instrumentation\DbInstrumentation;

$rows = DbInstrumentation::traceQuery('mysql', 'select', 'booking', function () use ($pdo, $sql) {
    return $pdo->query($sql);
}, $sql);
```

For controlled troubleshooting while keeping privacy defaults, capture sanitized SQL:

```bash
ELVEN_OTEL_CAPTURE_DB_STATEMENT=true
ELVEN_OTEL_REDACT_DB_STATEMENT=true
```

If the customer explicitly does not want library-side redaction, use the global switch instead of maintaining a large raw-attribute allowlist:

```bash
ELVEN_OTEL_REDACTION_ENABLED=false
```

This keeps span/log/header values raw. DB statements still require `ELVEN_OTEL_CAPTURE_DB_STATEMENT=true`; otherwise `DbInstrumentation::traceQuery()` exports only `db.query.summary`. Metric labels still remain allowlisted and low-cardinality. Do not disable redaction in production without a written privacy exception and Collector/backend controls.

## Monolog 1

```php
use Elven\Observability\PhpLegacy\Logs\MonologTraceProcessor;
use Elven\Observability\PhpLegacy\Logs\MonologOtlpHandler;

$logger->pushProcessor(new MonologTraceProcessor());
$logger->pushHandler(new MonologOtlpHandler());
```

For custom log wrappers:

```php
$context = Observability::logs()->correlate($context);
```

The OTLP handler sends logs to Collector `/v1/logs` for routing to Loki. If the app already has file/stdout scraping into Loki, keep only `MonologTraceProcessor` and set `OTEL_LOGS_EXPORTER=none`.

## NGINX And PHP-FPM

Forward W3C trace headers into PHP-FPM:

```nginx
fastcgi_param HTTP_TRACEPARENT $http_traceparent;
fastcgi_param HTTP_TRACESTATE  $http_tracestate;
fastcgi_param HTTP_BAGGAGE     $http_baggage;
```

## Rollout

1. Enable in staging.
2. Validate server spans, downstream spans, metrics, and log correlation.
3. Validate redaction using fake JWT, fake email, fake CPF, fake card, and SQL with literals.
4. Canary one replica or one small PHP-FPM pool.
5. Watch latency, error rate, `elven.php.exporter.failed_exports`, and `elven.php.exporter.dropped_spans`.
6. Expand gradually.
7. Keep `ELVEN_OTEL_ENABLED=false` ready as the kill switch.
