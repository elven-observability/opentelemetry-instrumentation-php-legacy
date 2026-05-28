# Changelog

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
