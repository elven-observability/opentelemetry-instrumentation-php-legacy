# Changelog

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
