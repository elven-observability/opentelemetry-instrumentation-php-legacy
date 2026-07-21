# Elven OpenTelemetry Instrumentation for Legacy PHP

Production-oriented traces, metrics, OTLP logs, and log correlation for PHP `>=7.3.13`, without PECL and without the official OpenTelemetry SDK.

The library exports OTLP HTTP/JSON directly to an OpenTelemetry Collector, continues W3C trace context, and is designed so telemetry failures never change the application result.

## Read This First

This is **not zero-code auto-instrumentation**. PHP 7.3/7.4 cannot safely observe arbitrary function calls without a native extension. After you connect the library to the application's central entrypoints, however, every request or dependency call that passes through those entrypoints is instrumented automatically.

Connect these once:

| Application point | Result |
|---|---|
| Front controller/router | SERVER spans, request duration/errors/memory, inbound propagation |
| Shared Guzzle/cURL helper | CLIENT spans, dependency metrics, outbound propagation |
| Shared DB wrapper | DB spans, query summary, optional sanitized statement |
| AWS SDK client factory | CLIENT spans and signed `traceparent` propagation |
| Cache/Mongo/mail/queue wrapper | Dependency spans and bounded metrics |
| Monolog/custom logger | `trace_id`/`span_id`; optional OTLP logs |
| CLI/job entrypoint | Root job span, duration/error metrics, final flush |

Installing the Composer package and setting env vars alone does not intercept calls that bypass those shared points.

## What You Get

- OTLP HTTP/JSON traces at `/v1/traces`.
- OTLP HTTP/JSON metrics at `/v1/metrics`.
- Optional OTLP HTTP/JSON logs at `/v1/logs`, routed to Loki by the Collector.
- W3C `traceparent`, bounded `tracestate`, and bounded `baggage` extraction/injection.
- Exit-safe request scopes for legacy response helpers that call `exit` or `die`.
- Guzzle 6/7 middleware and cURL/header helpers.
- AWS SDK for PHP v3 middleware registered before SigV4 signing.
- Manual wrappers for mysqli/PDO-style calls, MongoDB, Redis, Memcached, SOAP, AMQP, mail, AWS, and search.
- Low-cardinality request, dependency, cache, job, exporter, and business metrics.
- Monolog 1/2 correlation and optional OTLP log handler.
- Privacy defaults, per-request memory limits, circuit breaker, timeouts, and a total kill switch.

## Requirements

- PHP `>=7.3.13` (tested on 7.3, 7.4, and PHP 8.x).
- Composer and `ext-json`.
- An OpenTelemetry Collector reachable from the PHP runtime.
- Recommended: `ext-curl` for lower-overhead export.

No PECL `opentelemetry` extension is required. No Elven token is required when the application sends to a customer-owned/local Collector; backend credentials remain in the Collector.

## 1. Install

```bash
composer require elven-observability/opentelemetry-instrumentation-php-legacy:^0.6
```

If Packagist is not available in the target network, use the public GitHub repository:

```bash
composer config repositories.elven-php-legacy vcs https://github.com/elven-observability/opentelemetry-instrumentation-php-legacy
composer require elven-observability/opentelemetry-instrumentation-php-legacy:^0.6
```

The package keeps compatibility with applications that declare:

```json
{
  "config": {
    "platform": {
      "php": "7.3.13"
    }
  }
}
```

## 2. Configure

Start with this staging-safe profile. Replace only service identity and Collector host.

```bash
# Total switch
ELVEN_OTEL_ENABLED=true
ELVEN_OTEL_DEBUG=false

# Stable service identity
OTEL_SERVICE_NAME=legacy-public-api
OTEL_SERVICE_NAMESPACE=customer-platform
OTEL_SERVICE_VERSION=1.0.0
OTEL_DEPLOYMENT_ENVIRONMENT=staging

# Collector. Signal-specific endpoints are optional.
OTEL_EXPORTER_OTLP_ENDPOINT=http://otel-collector:4318
OTEL_EXPORTER_OTLP_PROTOCOL=http/json
OTEL_EXPORTER_OTLP_TIMEOUT=200

# Signals
OTEL_TRACES_EXPORTER=otlp
OTEL_METRICS_EXPORTER=otlp
OTEL_LOGS_EXPORTER=none

# Propagation and sampling
OTEL_PROPAGATORS=tracecontext,baggage
OTEL_TRACES_SAMPLER=parentbased_traceidratio
OTEL_TRACES_SAMPLER_ARG=1

# Correlate existing logs even when OTLP logs are off
ELVEN_OTEL_LOG_CORRELATION_ENABLED=true

# Safe SQL visibility: query text is captured only after sanitization
ELVEN_OTEL_REDACTION_ENABLED=true
ELVEN_OTEL_CAPTURE_DB_STATEMENT=true
ELVEN_OTEL_REDACT_DB_STATEMENT=true
ELVEN_OTEL_ALLOW_RAW_ATTRIBUTES=

# Request-local memory/cardinality limits
ELVEN_OTEL_MAX_SPANS_PER_REQUEST=256
ELVEN_OTEL_MAX_METRIC_POINTS_PER_REQUEST=512
ELVEN_OTEL_MAX_LOG_RECORDS_PER_REQUEST=512
ELVEN_OTEL_MAX_ATTRIBUTES_PER_SPAN=128
ELVEN_OTEL_MAX_ATTRIBUTE_LENGTH=4096
ELVEN_OTEL_MAX_EVENTS_PER_SPAN=64
ELVEN_OTEL_MAX_EVENT_ATTRIBUTES=32
```

Defaults already resolve signal URLs as:

- `${OTEL_EXPORTER_OTLP_ENDPOINT}/v1/traces`
- `${OTEL_EXPORTER_OTLP_ENDPOINT}/v1/metrics`
- `${OTEL_EXPORTER_OTLP_ENDPOINT}/v1/logs`

Use `OTEL_EXPORTER_OTLP_TRACES_ENDPOINT`, `OTEL_EXPORTER_OTLP_METRICS_ENDPOINT`, or `OTEL_EXPORTER_OTLP_LOGS_ENDPOINT` only when the routes differ. Signal-specific protocol env vars are respected; v0.6 supports only `http/json`, and an unsupported protocol disables only that signal.

Kill switch:

```bash
ELVEN_OTEL_ENABLED=false
```

It wins over application configuration and turns the library into safe no-op behavior.

## 3. Make Env Vars Visible To PHP-FPM

Container env vars are not automatically visible to PHP code when the FPM pool keeps its secure `clear_env` default. Add an explicit allowlist to the pool config used in the built image:

```ini
; /usr/local/etc/php-fpm.d/zz-otel-env.conf (official php:fpm image)
[www]
env[ELVEN_OTEL_ENABLED] = $ELVEN_OTEL_ENABLED
env[ELVEN_OTEL_DEBUG] = $ELVEN_OTEL_DEBUG
env[OTEL_SERVICE_NAME] = $OTEL_SERVICE_NAME
env[OTEL_SERVICE_NAMESPACE] = $OTEL_SERVICE_NAMESPACE
env[OTEL_SERVICE_VERSION] = $OTEL_SERVICE_VERSION
env[ELVEN_ENVIRONMENT] = $ELVEN_ENVIRONMENT
env[OTEL_DEPLOYMENT_ENVIRONMENT] = $OTEL_DEPLOYMENT_ENVIRONMENT
env[OTEL_RESOURCE_ATTRIBUTES] = $OTEL_RESOURCE_ATTRIBUTES
env[OTEL_EXPORTER_OTLP_ENDPOINT] = $OTEL_EXPORTER_OTLP_ENDPOINT
env[OTEL_EXPORTER_OTLP_TRACES_ENDPOINT] = $OTEL_EXPORTER_OTLP_TRACES_ENDPOINT
env[OTEL_EXPORTER_OTLP_METRICS_ENDPOINT] = $OTEL_EXPORTER_OTLP_METRICS_ENDPOINT
env[OTEL_EXPORTER_OTLP_LOGS_ENDPOINT] = $OTEL_EXPORTER_OTLP_LOGS_ENDPOINT
env[OTEL_EXPORTER_OTLP_PROTOCOL] = $OTEL_EXPORTER_OTLP_PROTOCOL
env[OTEL_EXPORTER_OTLP_TRACES_PROTOCOL] = $OTEL_EXPORTER_OTLP_TRACES_PROTOCOL
env[OTEL_EXPORTER_OTLP_METRICS_PROTOCOL] = $OTEL_EXPORTER_OTLP_METRICS_PROTOCOL
env[OTEL_EXPORTER_OTLP_LOGS_PROTOCOL] = $OTEL_EXPORTER_OTLP_LOGS_PROTOCOL
env[OTEL_EXPORTER_OTLP_HEADERS] = $OTEL_EXPORTER_OTLP_HEADERS
env[OTEL_EXPORTER_OTLP_TIMEOUT] = $OTEL_EXPORTER_OTLP_TIMEOUT
env[ELVEN_OTEL_EXPORT_TIMEOUT_MS] = $ELVEN_OTEL_EXPORT_TIMEOUT_MS
env[OTEL_TRACES_EXPORTER] = $OTEL_TRACES_EXPORTER
env[OTEL_METRICS_EXPORTER] = $OTEL_METRICS_EXPORTER
env[OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE] = $OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE
env[ELVEN_OTEL_METRICS_TEMPORALITY] = $ELVEN_OTEL_METRICS_TEMPORALITY
env[OTEL_LOGS_EXPORTER] = $OTEL_LOGS_EXPORTER
env[OTEL_PROPAGATORS] = $OTEL_PROPAGATORS
env[OTEL_TRACES_SAMPLER] = $OTEL_TRACES_SAMPLER
env[OTEL_TRACES_SAMPLER_ARG] = $OTEL_TRACES_SAMPLER_ARG
env[ELVEN_OTEL_LOG_CORRELATION_ENABLED] = $ELVEN_OTEL_LOG_CORRELATION_ENABLED
env[ELVEN_OTEL_REDACTION_ENABLED] = $ELVEN_OTEL_REDACTION_ENABLED
env[ELVEN_OTEL_CAPTURE_DB_STATEMENT] = $ELVEN_OTEL_CAPTURE_DB_STATEMENT
env[ELVEN_OTEL_REDACT_DB_STATEMENT] = $ELVEN_OTEL_REDACT_DB_STATEMENT
env[ELVEN_OTEL_ALLOW_RAW_ATTRIBUTES] = $ELVEN_OTEL_ALLOW_RAW_ATTRIBUTES
env[ELVEN_OTEL_MAX_SPANS_PER_REQUEST] = $ELVEN_OTEL_MAX_SPANS_PER_REQUEST
env[ELVEN_OTEL_MAX_METRIC_POINTS_PER_REQUEST] = $ELVEN_OTEL_MAX_METRIC_POINTS_PER_REQUEST
env[ELVEN_OTEL_MAX_LOG_RECORDS_PER_REQUEST] = $ELVEN_OTEL_MAX_LOG_RECORDS_PER_REQUEST
env[ELVEN_OTEL_MAX_ATTRIBUTES_PER_SPAN] = $ELVEN_OTEL_MAX_ATTRIBUTES_PER_SPAN
env[ELVEN_OTEL_MAX_ATTRIBUTE_LENGTH] = $ELVEN_OTEL_MAX_ATTRIBUTE_LENGTH
env[ELVEN_OTEL_MAX_EVENTS_PER_SPAN] = $ELVEN_OTEL_MAX_EVENTS_PER_SPAN
env[ELVEN_OTEL_MAX_EVENT_ATTRIBUTES] = $ELVEN_OTEL_MAX_EVENT_ATTRIBUTES
env[ELVEN_OTEL_CAPTURE_CLIENT_ADDRESS] = $ELVEN_OTEL_CAPTURE_CLIENT_ADDRESS
env[ELVEN_OTEL_ID_HASH_SALT] = $ELVEN_OTEL_ID_HASH_SALT
```

Remove lines your deployment never sets if the FPM image treats undefined source variables as configuration errors. Rebuild and redeploy the image; do not patch a live container as the durable fix.

For NGINX/FastCGI inbound propagation:

```nginx
fastcgi_param HTTP_TRACEPARENT $http_traceparent;
fastcgi_param HTTP_TRACESTATE  $http_tracestate;
fastcgi_param HTTP_BAGGAGE     $http_baggage;
```

## 4. Initialize And Instrument The Front Controller

Initialize after Composer autoload. The library registers its own shutdown hook; do not register a second exporter shutdown callback.

For custom module-map routers whose response helpers may call `exit`:

```php
<?php

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/routes.php';

use Elven\Observability\PhpLegacy\Bridge\Legacy\FrontControllerInstrumentation;
use Elven\Observability\PhpLegacy\Observability;

Observability::init();
$scope = FrontControllerInstrumentation::beginFromGlobals($routes);
$error = null;

try {
    $rest = new RestUtils($routes);
    $rest->initRequest($urlTarget);
    $rest->processRequest();
} catch (\Throwable $exception) {
    $error = $exception;
    $scope->recordException($exception);
    throw $exception;
} finally {
    $scope->finish($error);
}
```

If `processRequest()` calls `exit`, PHP does not unwind the `finally`; the library closes the SERVER span from its internal shutdown registry before flushing. `finish()` is idempotent.

Routes are generated only from the known route map. Dynamic segments become `{id}`, `{action}`, and `{rest}` so request IDs cannot explode metric cardinality.

For Slim 2, use `Slim2Instrumentation`; for a known static route, use:

```php
use Elven\Observability\PhpLegacy\Instrumentation\HttpServerInstrumentation;

$result = HttpServerInstrumentation::instrument('/v1/customer/{id}', function ($span) use ($handler) {
    return $handler();
});
```

## 5. Instrument Guzzle 6 Or 7

Add the middleware in the shared client factory. Calls through that client receive CLIENT spans and child `traceparent` headers.

```php
use Elven\Observability\PhpLegacy\Instrumentation\GuzzleInstrumentation;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;

$stack = HandlerStack::create();
$stack->push(GuzzleInstrumentation::middleware(), 'elven.otel');
$client = new Client(array('handler' => $stack));
```

For applications that instantiate `new Client()` directly in many classes, consolidate construction into one factory. Calls made by uninstrumented client instances cannot be observed without PECL.

For cURL/custom HTTP wrappers, use `CurlInstrumentation` or `HttpClientInstrumentation`. Never attach request/response bodies.

## 6. Instrument SQL Statements

Wrap the single shared DB execution method:

```php
use Elven\Observability\PhpLegacy\Instrumentation\DbInstrumentation;

$result = DbInstrumentation::traceQuery(
    'mysql',
    $operation,
    'application',
    function ($span) use ($connection, $sql) {
        $result = $connection->query($sql);
        if ($result === false) {
            $span->setStatus('ERROR', 'database_error');
            $span->setAttribute('error.type', 'database_error');
        }
        return $result;
    },
    $sql
);
```

With the staging profile above, DB spans contain:

- `db.system.name=mysql`
- `db.namespace=application`
- `db.operation.name=SELECT`
- `db.query.summary=SELECT customers`
- `db.query.text` and legacy `db.statement`, with literals replaced by `?`

Raw SQL is not available unless both capture is enabled and redaction is explicitly disabled. Do not use a per-tenant physical database name as a metric label. Keep `db.namespace` bounded or logical when physical names contain customer identifiers.

## 7. Instrument AWS SDK v3

Register each shared client once. The helper is idempotent and injects before SigV4 signs the request.

```php
use Elven\Observability\PhpLegacy\Instrumentation\AwsInstrumentation;

$sns = AwsInstrumentation::register($sns, 'sns');
$ses = AwsInstrumentation::register($ses, 'ses');
$s3 = AwsInstrumentation::register($s3, 's3');
$dynamo = AwsInstrumentation::register($dynamo, 'dynamodb');
```

Do not capture message bodies, email recipients, bucket object keys, credentials, or presigned URLs.

## 8. Instrument Mongo, Cache, Mail, And Queues

```php
use Elven\Observability\PhpLegacy\Instrumentation\CacheInstrumentation;
use Elven\Observability\PhpLegacy\Instrumentation\MongoInstrumentation;

$document = MongoInstrumentation::trace('find_one', 'application', 'instances', function () use ($collection, $filter) {
    return $collection->findOne($filter);
});

$cached = CacheInstrumentation::observe('configuration', function () use ($cache, $key) {
    return $cache->get($key);
});
```

Mongo filters/documents, cache keys, recipients, queue message bodies, and object keys are never required for useful telemetry and should not be attached.

Available helpers include `RedisInstrumentation`, `MemcachedInstrumentation`, `MessagingInstrumentation`, `AmqpInstrumentation`, `MailInstrumentation`, `SoapInstrumentation`, and `SearchInstrumentation`.

## 9. Identify A Tenant Safely

Multi-tenant applications must not put raw API tokens, customer IDs, user IDs, or tenant IDs in resource attributes or metric labels.

Hash an identifier when it becomes known:

```php
Observability::context()->setHashed('tenant.id', $apiToken);
$scope->setHashedAttribute('tenant.id', $apiToken);
```

Set `ELVEN_OTEL_ID_HASH_SALT` from the deployment secret manager when the same pseudonym must correlate across requests. Without it, the library uses a secure ephemeral HMAC key: low-entropy IDs remain protected, but their hashes are intentionally not stable across PHP request lifecycles. The hash is request context/span data only; it is not an allowed metric label. The front-controller scope resets baggage at every request to prevent tenant leakage between reused PHP-FPM workers.

## 10. Correlate Or Export Logs

Existing pipeline only, with trace correlation:

```php
$context = Observability::logs()->correlate($context);
$logger->info('configuration loaded', $context);
```

Monolog 1 or 2:

```php
use Elven\Observability\PhpLegacy\Logs\MonologTraceProcessor;

$logger->pushProcessor(new MonologTraceProcessor());
```

To export application logs over OTLP, set:

```bash
OTEL_LOGS_EXPORTER=otlp
```

Then add `MonologOtlpHandler` or call `Observability::logs()->emit(...)`. The env var alone cannot observe arbitrary existing logger calls.

```php
use Elven\Observability\PhpLegacy\Logs\MonologOtlpHandler;

$logger->pushHandler(new MonologOtlpHandler());
```

Do not enable both OTLP log export and file/stdout collection for the same records unless duplicate Loki entries are intentional. The library sends to the Collector, not directly to Loki.

## 11. Instrument CLI Jobs

```php
use Elven\Observability\PhpLegacy\Instrumentation\CliInstrumentation;

$exitCode = CliInstrumentation::run('send-notifications', function ($span) {
    return runExistingJob();
}, array(), true);
```

The wrapper preserves the exact return value/exception, records bounded job metrics, closes the span, and optionally flushes before process exit.

## Automatic Metrics

- `http.server.request.duration` (seconds)
- `elven.php.request.memory.peak`
- `elven.php.request.errors`
- `elven.php.dependency.duration` (milliseconds)
- `elven.php.cache.operations`
- `elven.php.cache.operation.duration`
- `elven.php.job.duration`
- `elven.php.job.errors`
- `elven.php.exporter.dropped_spans`
- `elven.php.exporter.dropped_metric_points`
- `elven.php.exporter.dropped_log_records`
- `elven.php.exporter.failed_exports`

Business metric example:

```php
Observability::metrics()->counter('customer.feedback.created')->add(1, array(
    'operation' => 'feedback_create',
));
```

Metric labels are allowlisted and normalized even when redaction is disabled. Never use tenant/user/request/order/trace IDs, tokens, CPF/email, URLs, SQL, cache keys, message IDs, or object keys as labels.

## Privacy Modes

Recommended:

```bash
ELVEN_OTEL_REDACTION_ENABLED=true
ELVEN_OTEL_CAPTURE_DB_STATEMENT=true
ELVEN_OTEL_REDACT_DB_STATEMENT=true
```

Raw troubleshooting mode, only with explicit customer approval and downstream access controls:

```bash
ELVEN_OTEL_REDACTION_ENABLED=false
ELVEN_OTEL_CAPTURE_DB_STATEMENT=true
ELVEN_OTEL_REDACT_DB_STATEMENT=false
```

Turning redaction off permits raw span/log values. It does not remove metric label allowlists, size limits, or cardinality guards.

## Local Validation

The host does not need PHP or Composer:

```bash
docker compose run --rm php73 composer check
docker compose run --rm php74 composer check
```

Fake Collector:

```bash
docker compose up fake-collector
```

It accepts and records `/v1/traces`, `/v1/metrics`, and `/v1/logs` for integration tests.

## Staging Proof

Before a canary, prove all of these from the PHP-FPM request path, not only from container `printenv` or a CLI job:

1. A temporary HTTP diagnostic sees `ELVEN_OTEL_ENABLED` and the OTLP endpoint.
2. The Collector returns 2xx for `/v1/traces`, `/v1/metrics`, and `/v1/logs` when enabled.
3. One HTTP request produces a SERVER span with a stable route and real status code.
4. One Guzzle/cURL/AWS call is a child CLIENT span and carries `traceparent`.
5. One DB operation contains `db.query.summary`; sanitized text appears only when configured.
6. One application log contains the same `trace_id`/`span_id` as the active span.
7. Tempo, Mimir/Prometheus, and Loki show the expected service/resource identity.
8. Kill switch makes all exporters no-op without changing application behavior.

Roll out staging, one canary replica, then the remaining pool. Watch PHP-FPM saturation, p95/p99 latency, HTTP errors, failed exports, and dropped telemetry. Do not restart production outside a controlled window.

## Troubleshooting Quick Checks

No telemetry:

```php
var_dump(getenv('ELVEN_OTEL_ENABLED'));
var_dump(getenv('OTEL_EXPORTER_OTLP_ENDPOINT'));
```

If CLI sees env vars and HTTP does not, fix the PHP-FPM pool allowlist. If traces work but metrics do not, verify the signal-specific endpoint/protocol and Collector metrics pipeline. If OTLP logs are enabled but absent, verify the application actually calls `MonologOtlpHandler` or `logs()->emit()`.

Collector failure never throws into the application. Export timeout is bounded to 30 seconds, defaults to 200ms, and endpoint plus Collector-origin circuit breakers suppress repeated failures across PHP-FPM requests and workers. Their bounded state contains no telemetry and lives in the effective runtime user's private temporary directory; keep `sys_get_temp_dir()` writable.

## Documentation

- [Environment reference](docs/env-vars.md)
- [Architecture and design](docs/SDD.md)
- [PHP 7.4 front-controller integration](docs/php74-front-controller-integration.md)
- [Slim 2 integration](docs/legacy-slim2-integration.md)
- [Privacy](docs/privacy.md)
- [Performance](docs/performance.md)
- [Traffic attribution](docs/traffic-attribution.md)
- [Troubleshooting](docs/troubleshooting.md)
- [Security policy](SECURITY.md)
