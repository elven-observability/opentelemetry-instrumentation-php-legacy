# Privacy

## Defaults

This library is private by default:

- No request body capture.
- No response body capture.
- No SOAP XML capture.
- No raw message payload capture.
- No raw DB statement export.
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

## Database Statements

`db.statement`, `db.query.text`, `db.sql.text`, and parameter attributes are redacted by default. If statement capture is explicitly enabled, literals and numeric parameters are replaced with `?`, and a safe `db.query.summary` is produced.

## Metric Labels

Only these labels are accepted: `service_name`, `service_namespace`, `environment`, `route`, `method`, `status_code`, `dependency_type`, `dependency_name`, `operation`, `error_type`.

Never use request IDs, order IDs, user IDs, tokens, CPF/email, trace IDs, or session IDs as metric labels.

Allowed metric labels are still normalized: routes are path-sanitized, HTTP methods/status codes are constrained, and high-cardinality-looking `operation`, `dependency_name`, and `error_type` values collapse to safe placeholders.
