# Changelog

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
