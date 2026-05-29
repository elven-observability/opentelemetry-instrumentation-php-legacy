# Privacy

## Defaults

This library is private by default:

- No request body capture.
- No response body capture.
- No SOAP XML capture.
- No raw message payload capture.
- No raw DB statement export.
- OTLP log export is off until `OTEL_LOGS_EXPORTER=otlp` is set.
- OTLP log body and attributes are redacted and bounded before export.
- No raw Redis/Memcached keys by default.
- No raw Mongo query/document capture.
- No raw Elasticsearch/OpenSearch query capture.
- No raw client IP capture unless `ELVEN_OTEL_CAPTURE_CLIENT_ADDRESS=true`.
- No raw exception messages unless explicitly allowlisted.

## Redacted Headers And Keys

Names matching these patterns are redacted: `Authorization`, `Cookie`, `Set-Cookie`, `X-Api-Key`, `token`, `password`, `passwd`, `secret`, `bearer`, `session`, `api_key`, `cpf`, `email`, and `card`.

## Redacted Values

String values are scanned for:

- Bearer tokens.
- JWT-like tokens.
- Email addresses.
- CPF-like values.
- Payment-card-like numbers.
- High-cardinality opaque identifiers in URL paths and metric labels.

## Logs

When `OTEL_LOGS_EXPORTER=otlp`, the library exports log records to Collector `/v1/logs`. It does not send directly to Loki.

Log messages and context/extra attributes are still application-controlled text, so treat them as sensitive. The library redacts common token, password, CPF, email, card, cookie, Authorization, and secret patterns, truncates very large values, and avoids serializing objects or exception stack traces by default.

Do not log request/response bodies, SOAP XML, customer payloads, raw user identifiers, raw DB statements, tokens, or private keys. If logs are already scraped from files/stdout, enable only `MonologTraceProcessor` and keep `OTEL_LOGS_EXPORTER=none` to avoid duplicate Loki records.

## Database Statements

`db.statement`, `db.query.text`, `db.sql.text`, and parameter attributes are redacted by default. If statement capture is explicitly enabled, literals and numeric parameters are replaced with `?`, and a safe `db.query.summary` is produced.

## Metric Labels

Only these labels are accepted: `service_name`, `service_namespace`, `environment`, `route`, `method`, `status_code`, `dependency_type`, `dependency_name`, `operation`, `error_type`, `traffic_source`, `traffic_channel`.

Never use request IDs, order IDs, user IDs, tokens, CPF/email, trace IDs, or session IDs as metric labels.

Allowed metric labels are still normalized: routes are path-sanitized, HTTP methods/status codes are constrained, and high-cardinality-looking `operation`, `dependency_name`, and `error_type` values collapse to safe placeholders.

Traffic attribution labels are also normalized. `traffic_source` is limited to stable categories such as `front`, `google_flights`, `skyscanner`, `mundi`, `kayak`, `viajala`, `backend`, `other`, and `unknown`; high-cardinality-looking raw values collapse instead of being exported.
