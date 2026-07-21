# PHP 7.4 Front-Controller Integration

This guide targets custom PHP-FPM applications with one `index.php`, a route map, shared dependency wrappers, Guzzle 6, mysqli, MongoDB, and AWS SDK v3.

## Integration Contract

The library does not patch PHP internals. Coverage is determined by which central boundaries are wired:

1. `index.php` starts the SERVER scope.
2. The shared Guzzle/cURL factory starts CLIENT spans and injects context.
3. The shared DB execution method starts DB spans.
4. Shared AWS clients register the SDK middleware.
5. Logger factories correlate or export records.
6. Job entrypoints create root job spans and flush.

Do not add instrumentation independently to hundreds of controllers. Central hooks are easier to review, harder to bypass, and cheaper at runtime.

## Composer

```bash
composer require elven-observability/opentelemetry-instrumentation-php-legacy:^0.6
```

Keep the application's PHP platform unchanged. Do not add PECL OpenTelemetry or the official SDK to the same runtime.

## PHP-FPM

Prove env visibility through an HTTP request. `docker exec ... printenv` proves only the container environment, not the FPM worker environment.

Create a pool fragment in the image and explicitly forward the required `OTEL_*` and `ELVEN_OTEL_*` env vars. Use the allowlist in the README. `clear_env=no` is simpler but exposes every container secret to PHP and is not the recommended default for a multi-tenant application.

Forward incoming W3C headers through NGINX:

```nginx
fastcgi_param HTTP_TRACEPARENT $http_traceparent;
fastcgi_param HTTP_TRACESTATE  $http_tracestate;
fastcgi_param HTTP_BAGGAGE     $http_baggage;
```

## Front Controller

Initialize after Composer autoload and after the route map exists:

```php
use Elven\Observability\PhpLegacy\Bridge\Legacy\FrontControllerInstrumentation;
use Elven\Observability\PhpLegacy\Observability;

Observability::init();
$scope = FrontControllerInstrumentation::beginFromGlobals($routes);
$error = null;

try {
    $router->initRequest($urlTarget);
    $router->processRequest();
} catch (\Throwable $exception) {
    $error = $exception;
    $scope->recordException($exception);
    throw $exception;
} finally {
    $scope->finish($error);
}
```

The route helper accepts only modules/submodules present in the route map. Unknown modules become `/{unmatched}` and dynamic segments become placeholders. This prevents attacker-controlled URLs from becoming metric labels or span names.

Legacy response helpers often call `exit`. PHP does not reliably unwind application callbacks in that path, so the scope also registers with the library shutdown registry. The scope closes before traces/metrics/logs flush.

Do not register another application shutdown function that calls `Observability::shutdown()`. The library already does it, and duplicate callbacks obscure ordering.

## Tenant Isolation

When authentication resolves the tenant, hash the identifier before adding it to telemetry:

```php
use Elven\Observability\PhpLegacy\Observability;
use Elven\Observability\PhpLegacy\Privacy\IdentifierHasher;

$tenantHash = IdentifierHasher::hash($apiToken);
Observability::context()->set('tenant.id', $tenantHash);

$span = Observability::tracer()->currentSpan();
if ($span) {
    $span->setAttribute('tenant.id', $tenantHash);
}
```

Configure `ELVEN_OTEL_ID_HASH_SALT` from a secret manager when source identifiers have low entropy. Never use the raw token, tenant hash, or tenant name as a metric label. Never place per-request tenant identity in `OTEL_RESOURCE_ATTRIBUTES`; resources describe the process/service, not the current request.

The SERVER entrypoint resets baggage every request. Any custom worker loop must call `Observability::context()->reset()` before handling the next message.

## mysqli Wrapper

Instrument the shared `execute()` method, not every model:

```php
use Elven\Observability\PhpLegacy\Instrumentation\DbInstrumentation;

public function execute($multiQuery = false)
{
    if (!$this->query) {
        throw new \RuntimeException('Query not configured');
    }

    $sql = $this->query;
    $operation = strtoupper((string) strtok(ltrim($sql), " \t\r\n"));

    return DbInstrumentation::traceQuery(
        'mysql',
        $operation,
        'application',
        function ($span) use ($multiQuery, $sql) {
            $result = $multiQuery
                ? $this->connection->multi_query($sql)
                : $this->connection->query($sql);

            if ($result === false) {
                $span->setStatus('ERROR', 'database_error');
                $span->setAttribute('error.type', 'database_error');
            }
            return $result;
        },
        $sql
    );
}
```

Do not set a tenant-specific physical DB name as a metric label. `db.namespace` is a span attribute and should still be a bounded logical name when the physical name identifies a customer.

With capture and redaction enabled, literals are removed before `db.query.text`/`db.statement` are retained. `db.query.summary` is always the preferred grouping field.

## MongoDB Wrapper

Wrap each central operation without attaching filters/documents:

```php
use Elven\Observability\PhpLegacy\Instrumentation\MongoInstrumentation;

return MongoInstrumentation::trace('find_one', 'application', 'instances', function () use ($filter) {
    return $this->mongoCollection->findOne($filter);
});
```

Instrument `find`, `findOne`, `insertOne`, `updateOne`, and `deleteOne` boundaries. Preserve original return values and exceptions. Do not serialize the filter, update document, or inserted document into telemetry.

## Shared Guzzle 6 Factory

```php
private static function httpClient(array $options = array())
{
    $stack = \GuzzleHttp\HandlerStack::create();
    $stack->push(
        \Elven\Observability\PhpLegacy\Instrumentation\GuzzleInstrumentation::middleware(),
        'elven.otel'
    );
    $options['handler'] = $stack;
    return new \GuzzleHttp\Client($options);
}
```

Replace direct `new Client(...)` calls at shared helpers/factories. Preserve existing headers, timeout, proxy, TLS, retry, and handler options. Do not create a new `HandlerStack` that discards a caller-provided custom handler; compose with the existing stack when one is already supplied.

The middleware supports Guzzle 6 and 7, preserves synchronous exceptions/rejected promises, marks client 4xx/5xx as errors, and records only method, host, sanitized path, status, error type, and duration.

## AWS SDK v3

Immediately after creating a client:

```php
$client = new \Aws\Sns\SnsClient($config);
\Elven\Observability\PhpLegacy\Instrumentation\AwsInstrumentation::register($client, 'sns');
```

Apply to SES, SNS, S3, DynamoDB, and CloudWatch factories. Registration is idempotent. The middleware runs after request serialization and before SigV4, so the propagated header is included in the signature.

Stream-wrapper S3 calls need separate manual spans because the eventual network call is hidden behind `file_get_contents`, `file_put_contents`, or `rename`.

## Logs

For Monolog 1/2 factories:

```php
$logger->pushProcessor(
    new \Elven\Observability\PhpLegacy\Logs\MonologTraceProcessor()
);
```

If OTLP logs are required:

```php
$logger->pushHandler(
    new \Elven\Observability\PhpLegacy\Logs\MonologOtlpHandler()
);
```

`OTEL_LOGS_EXPORTER=otlp` enables the exporter but cannot observe a logger that never calls the handler/facade. Existing stdout/file/CloudWatch/GCP logging can remain active with correlation only.

Before adding correlation, remove existing raw token/API key/user identity logging. Correlation does not sanitize strings already emitted through a different logger pipeline.

## CLI And Scheduled Jobs

```php
Observability::init();

$exitCode = \Elven\Observability\PhpLegacy\Instrumentation\CliInstrumentation::run(
    'job-name',
    function ($span) {
        return runJob();
    },
    array(),
    true
);
```

For a daemon that processes many messages, create and close a CONSUMER span per message, reset request context between messages, and flush at controlled intervals. Do not leave one span open for the process lifetime.

## Rollout Order

1. Add dependency and FPM env allowlist with `ELVEN_OTEL_ENABLED=false`.
2. Deploy and prove no behavior/latency change.
3. Enable SERVER scope and existing-log correlation in staging.
4. Add shared HTTP and DB wrappers.
5. Add AWS/Mongo/cache/mail/queue boundaries.
6. Enable OTLP logs only after duplicate-log analysis.
7. Run a one-replica canary and validate trace continuity plus all signal pipelines.
8. Expand while watching p95/p99, FPM queue/saturation, 5xx, failed exports, and dropped telemetry.

Do not restart or roll production outside the customer's controlled window.

## Acceptance Evidence

- HTTP/FPM diagnostic proves env visibility.
- One inbound parent `traceparent` continues into the PHP SERVER span.
- Guzzle and AWS requests contain child `traceparent`.
- AWS SigV4 `SignedHeaders` includes `traceparent`.
- DB span contains safe summary and sanitized statement according to env.
- A request ending through `exit` still has a closed span and real HTTP status.
- Existing logger record contains active `trace_id` and `span_id`.
- OTLP log reaches Collector only when handler/facade and exporter are enabled.
- No raw token, body, Mongo document/filter, SQL literal, recipient, or object key appears.
- Kill switch leaves application output and exceptions unchanged.
