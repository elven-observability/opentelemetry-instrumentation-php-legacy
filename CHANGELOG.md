# Changelog

## 0.6.0 - 2026-07-20

- Added exit-safe `ServerRequestScope` and route-map-based `FrontControllerInstrumentation` for custom PHP 7.4 applications whose response helpers terminate through `exit`/`die`.
- Added tested Guzzle 6/7 middleware behavior for client 4xx/5xx, synchronous failures, rejected promises, propagation, and dependency duration.
- Expanded log processor/handler compatibility to Monolog 1 and 2, including the PHP 7.4 customer's exact Monolog 2.9.3 line.
- Added idempotent AWS SDK v3 registration at the pre-SigV4 sign stage. The PHP 7.4 compatibility fixture proves `traceparent` is included in AWS `SignedHeaders`.
- Added `CliInstrumentation` for bounded job spans, duration/error metrics, exact exception preservation, and optional final flush.
- Added current stable DB semantic-convention attributes (`db.system.name`, `db.namespace`, `db.query.summary`, `db.query.text`) while retaining legacy attribute aliases for existing queries.
- Added pseudonymous identifier hashing with optional `ELVEN_OTEL_ID_HASH_SALT`; raw identifier-like baggage is dropped while explicit 32-hex pseudonyms may propagate.
- Added per-span attribute/event/value limits, bounded resource attributes, safe span/status names, oversized baggage/tracestate rejection, and stronger low-cardinality metric enum normalization.
- Added signal-specific OTLP protocol resolution. Unsupported protocols never receive JSON payloads and disable only the affected signal.
- Capped synchronous export timeout at 30 seconds, spans at 2048, metric/log points at 4096 per request, and individual attribute values at 16384 bytes; production defaults remain substantially lower.
- Reduced request hot-path work by reading only allowlisted traffic-attribution fields instead of merging complete GET/POST arrays.
- Added an isolated PHP 7.4 compatibility fixture for Guzzle 6.5.8, Monolog 2.9.3, and PSR Log 1.1.4. Its explicit Composer security-blocking bypass is confined to this end-of-life Guzzle compatibility proof; the package root remains audited without bypasses.
- Expanded CI through PHP 8.5 while retaining PHP 7.3/7.4 gates, and added dedicated Guzzle 6, Monolog 1, PHP 7.4 legacy, and current secure AWS SDK compatibility jobs.
- Rewrote the README and added a complete PHP 7.4 custom front-controller integration guide, including PHP-FPM env forwarding, SQL visibility, multi-tenant isolation, logs, AWS, jobs, rollout, and acceptance evidence.

## 0.5.10 - 2026-06-25

- Removed `process.pid` from resource attributes to prevent PHP-FPM worker recycling from creating unbounded Mimir time-series cardinality when resource attributes are promoted to metric labels.

## 0.5.9 - 2026-06-25

Hardening only — no new signals; makes the v0.5.6/v0.5.7 additions impossible to
let telemetry break or slow the request, and zero-cost when OTel is off.

- `HttpServerInstrumentation::instrument()` is now fully fail-safe: span creation
  runs under try/catch with a `NoopSpan` fallback (the handler always gets a
  usable span even if span/baggage setup throws); `recordException()` can no
  longer replace the real propagating exception; and the entire `finally`
  close-out (duration histogram + `finish()`) is guarded so a telemetry failure
  can never alter the return value or the thrown exception.
- `finish()` guards all metric/span emission (it is public and runs in a
  `finally`) so it never throws into the request path.
- High-level baggage seeding in the server span is gated on `isEnabled()` (strict
  zero-cost when OTel is off) and fully guarded.
- `CacheInstrumentation::record()` is gated on `isEnabled()` so the hot cache-read
  path costs nothing when OTel is off; `observe()` now records a driver-error
  outcome when the reader throws and rethrows (never swallows); `cache_name`
  sanitization falls back safely on a PCRE failure.
- `HeaderInjector::injectContext()` guards trace/baggage injection — propagation
  failures can never break the outbound HTTP/SOAP/AMQP call.
- `RequestContext` caps stored members (64) to stay bounded under misuse.

## 0.5.8 - 2026-06-25

- Changed OTLP metric counter/histogram temporality to `AGGREGATION_TEMPORALITY_CUMULATIVE` by default so Collector pipelines that export to Mimir through `prometheusremotewrite` translate `http.server.request.duration`, `elven.php.dependency.duration`, and other golden-signal metrics instead of accepting only gauges.
- Added `ELVEN_OTEL_METRICS_TEMPORALITY` and `OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE` support. Set `delta` only for Collector pipelines that explicitly support delta metrics; `lowmemory` maps to delta for standard compatibility.
- Hardened DB statement capture: `DbInstrumentation::traceQuery()` now attaches `db.statement` only when `ELVEN_OTEL_CAPTURE_DB_STATEMENT=true`, even if global redaction is disabled. Raw statements require both explicit capture and raw/redaction settings.
- Updated docs and troubleshooting for the “Collector accepts metrics but Mimir only shows gauges / `prometheusremotewrite_failed_translations` rises” failure mode.
- Bumped the reported `telemetry.sdk.version` / instrumentation scope version to `0.5.8`.

## 0.5.7 - 2026-06-25

- Cache effectiveness instrumentation. New `CacheInstrumentation` records cache
  read outcome and latency as low-cardinality telemetry: counter
  `elven.php.cache.operations{cache_name, result}` (result = hit|miss|error) and
  histogram `elven.php.cache.operation.duration` (ms). `observe()` wraps a reader
  and classifies the outcome (default: value=hit, false=miss, null=driver-error).
  Ultra-defensive: never throws on the cache path. `cache_name`/`result` added to
  the metric label allowlist. This surfaces the hit ratio (previously blind) and
  distinguishes a genuine miss from a driver failure.
- High-level request context propagation via W3C baggage. New `RequestContext` +
  `Observability::context()` hold request-scoped business context. The HTTP server
  span now resets it per request (FPM-safe), extracts inbound `baggage`, and seeds
  `traffic_source`/`traffic_channel`/`is_bot`. `HeaderInjector::injectContext()`
  now defaults the outgoing baggage to the current request context, so business
  context auto-propagates on every outbound hop (HTTP client, SOAP/DSG, AMQP)
  without each call site passing it — downstream services/spans inherit it.
  Sensitive keys are still dropped/redacted by `BaggagePropagator`.
- Error category. `elven.php.request.errors` now carries `error_category`
  (`technical` for exceptions/5xx, `client` for 4xx) alongside `error_type`, so
  "our fault" vs "client's fault" is separable at the metric level. Added to the
  allowlist. (Richer categories — business/validation/dependency — are reserved
  for app-side emission.)

## 0.5.6 - 2026-06-25

- Bot/crawler classification on HTTP server requests. A new `BotClassifier` maps
  the User-Agent to a low-cardinality outcome: `client.is_bot` (boolean) plus a
  bounded `bot.category` enum (search_engine, social, seo, monitoring, tooling,
  generic_bot, none). `client.is_bot` and `bot.category` are added to the SERVER
  span; only `is_bot` is promoted to request metrics (added to the metric label
  allowlist) to keep `elven.php.*` request-metric cardinality flat. The raw
  User-Agent is never used as a metric label. Classification is ultra-defensive
  (non-string/empty UA returns the human default) and never throws on the request
  path. Lets dashboards split demand by human vs automated traffic.
- Error mapping is now complete across the request outcome. `finish()` records a
  single bounded `elven.php.request.errors` increment with `error_type` of
  `exception`, `http_5xx`, or `http_4xx` (previously only `http_5xx` was counted).
  4xx client errors are now observable per status code without marking the SERVER
  span as ERROR (per HTTP semconv). Thrown handlers are reported exactly once via
  a new optional `$throwable` argument to `finish()`, so an exception is never
  missed (when the status still reads 200 mid-propagation) nor double-counted
  with `http_5xx`. Backward compatible: existing `finish($span, $status)` callers
  keep working and gain 4xx counting.

## 0.5.5 - 2026-06-18

- Emit `http.server.request.duration` in SECONDS (semconv unit `s`) with the OTel
  recommended second-scaled histogram boundaries instead of milliseconds. The
  metric now exports as `http_server_request_duration_seconds`, matching the
  semantic convention, Beyla, and existing dashboards — so the PHP instrumentation
  can be the definitive source of the HTTP golden signals (rate/errors/duration)
  and the temporary Beyla layer can eventually be retired without changing panels.
- `MetricFacade` now selects histogram boundaries by unit (seconds vs the default
  millisecond scale), so custom `elven.php.*` duration histograms keep their ms
  boundaries while seconds-unit histograms become percentile-usable.

## 0.5.4 - 2026-06-17

- Optimized the per-attribute redaction hot path with no behavior change. `UrlSanitizer::redactSensitiveText()` now runs each Bearer/JWT/email/CPF/card regex only when a cheap necessary-condition substring (or digit) is present, skipping the PCRE engine entirely for the common clean value (route names, enums, hostnames, low-cardinality labels).
- `AttributeRedactor` now memoizes the per-key redaction plan. Attribute keys are a small, bounded, repeating set, so the regex-heavy key classification is computed once per key per process instead of on every `redactValue()` call. Per-value scanning is unchanged (only the key plan is cached).
- Result: `redactAttributes()` over a realistic per-request attribute set is ~2.25x faster (about 6.9us to 3.1us per call in the new `benchmarks/redaction.php`), reducing instrumentation CPU on high-attribute/high-span requests. Redaction output is identical; added regression tests assert per-value scanning, key-plan memoization consistency, and pre-gate detection of mixed-case Bearer, embedded JWT, email, CPF and card values.

## 0.5.3 - 2026-06-17

- Hardened exporter failure paths for production rollouts: `ObservabilityHandle::shutdown()` is now idempotent even when an export fails, preventing duplicate shutdown flush attempts in applications that also register their own shutdown callback.
- Changed OTLP HTTP circuit breakers to be shared per worker and endpoint instead of being recreated for every exporter instance, so a temporarily unavailable Collector is skipped quickly after repeated failures.

## 0.5.2 - 2026-06-16

- Added traffic-attribution fallback for presence-only metasearch signals such as `skyScannerCode` and `gclid` in request data or query strings.
- Preserved low-cardinality behavior: dynamic click codes are never exported as metric labels, only mapped to stable sources such as `skyscanner` or `google`.
- Added regression tests for safe presence-only attribution.

## 0.5.1 - 2026-06-11

- Fixed environment resolution so empty `OTEL_*`/`ELVEN_*` variables are treated as unset. This matches OpenTelemetry environment-variable semantics and prevents PHP-FPM pool allowlists from exporting to an empty URL when signal-specific OTLP endpoints are declared but not set.
- Added regression coverage for empty signal-specific OTLP endpoints falling back to `OTEL_EXPORTER_OTLP_ENDPOINT`.

## 0.5.0 - 2026-06-09

- Added `ELVEN_OTEL_REDACTION_ENABLED` and explicit `redaction_enabled` config to disable library-side value redaction globally when a customer owns privacy controls downstream.
- Kept redaction enabled by default and preserved metric label allowlists, normalization, truncation, and high-cardinality protection even when value redaction is disabled.
- Updated README, environment docs, privacy docs, Slim 2 guide, troubleshooting, and tests for the new redaction opt-out behavior.

## 0.4.0 - 2026-05-29

- Centralized the reported library version in `Observability::VERSION` and the scope name in `Observability::SCOPE_NAME`; `ResourceBuilder` and the trace/metric/log OTLP exporters now read from these instead of a hardcoded `0.1.0`, so `telemetry.sdk.version` and the instrumentation scope version match the released package (fixes #1).
- Added an optional `$statusResolver` callback to `HttpServerInstrumentation::instrument()` and `Bridge\Legacy\RestRouteInstrumentation::traceRestAction()`. Frameworks that flush the HTTP status after the handler returns (e.g. Slim 2, where `http_response_code()` is still `200` when the span closes) can pass a resolver such as `function () use ($app) { return $app->response->getStatus(); }` so the SERVER span and the `http.server.request.duration` metric record the real status. Falls back to `http_response_code()` when no resolver is given or it returns an invalid value; resolver exceptions never break the request (fixes #2).

## 0.3.0 - 2026-05-29

- Added bounded traffic attribution through `Attribution\TrafficSourceResolver`.
- Added request-level metric attributes with `MetricFacade::setRequestAttributes()`, `addRequestAttributes()`, and `clearRequestAttributes()`.
- Added safe metric labels `traffic_source` and `traffic_channel`, with normalization for owned frontend, metasearch, paid, partner, and backoffice flows.
- Updated docs and tests so traffic attribution applies to all metrics emitted after request attributes are set.

## 0.2.0 - 2026-05-28

- Added OTLP HTTP/JSON logs export to Collector `/v1/logs`, with `OTEL_LOGS_EXPORTER=otlp` and `OTEL_EXPORTER_OTLP_LOGS_ENDPOINT`.
- Added bounded request-local log buffering, log redaction/truncation, dropped-log metrics, and export-failure metrics.
- Added `Logs\MonologOtlpHandler` for Monolog 1 OTLP log export alongside existing trace correlation.
- Updated fake collector, tests, examples, and docs for Collector-to-Loki log routing.

## 0.1.3 - 2026-05-28

- Reworked README into a copy/paste integration runbook for engineers and coding agents.
- Added explicit install, environment, NGINX/PHP-FPM, route, outbound propagation, DB, logs, metrics, validation, rollout, and troubleshooting steps.

## 0.1.2 - 2026-05-28

- Clarified public GitHub VCS installation while Packagist registration is pending.

## 0.1.1 - 2026-05-28

- Removed customer-specific public docs, examples, tests, and bridge names.
- Added generic legacy Slim 2/custom REST integration guide and bridge.
- Hardened `ELVEN_OTEL_ENABLED=false` so the env kill switch wins over explicit init config.
- Wired `ELVEN_OTEL_MAX_METRIC_POINTS_PER_REQUEST` into the metrics facade.
- Added regression tests for the env kill switch and metric point limit.
- Updated CI to use `actions/checkout@v6`.

## 0.1.0 - 2026-05-27

- Initial PHP legacy instrumentation library.
- Added custom OTLP HTTP/JSON trace and metric exporters.
- Added W3C Trace Context and Baggage propagation.
- Added privacy-first redaction, DB statement sanitization, metrics facade, Monolog 1 correlation, manual instrumentation wrappers, fake collector, Docker validation, and CI.
