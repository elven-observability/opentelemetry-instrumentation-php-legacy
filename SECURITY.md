# Security Policy

## Supported Versions

The supported line is `0.6.x` for PHP `>=7.3.13`.

## Reporting A Vulnerability

Report suspected vulnerabilities privately to `security@elvenobservability.com` with:

- Affected version or commit.
- Reproduction steps.
- Whether sensitive data can be exported or logged.
- Suggested mitigation if known.

Do not include real customer secrets, tokens, payloads, CPF, payment data, or private keys in reports. Use synthetic examples.

## Security Defaults

- Telemetry failures never throw into the application.
- Export timeouts are short.
- Sensitive headers and values are redacted.
- DB statements are omitted unless explicitly captured and are redacted by default.
- OTLP log export is opt-in and bounded.
- Span attributes/events, metric points, log records, baggage, and resource attributes have hard limits.
- Raw tenant/user identifiers are rejected from propagated baggage unless pseudonymized.
- App-side Elven tokens are not required when exporting to a customer Collector.

## Deployment Responsibilities

- Keep TLS verification enabled and send to a trusted Collector.
- Store Collector headers and identifier hash salts in deployment secret management.
- Do not disable redaction without explicit data-governance approval.
- Do not put per-request tenant identity, tokens, or customer identifiers in resource attributes or metric labels.
- Remove raw credentials and tokens from the application before adding log correlation; the library cannot sanitize records emitted through an unrelated pipeline.
- PHP 7.4 and several legacy ecosystem packages are end-of-life. This library minimizes its own dependency surface but does not make vulnerable application dependencies safe.

The public CI contains an isolated Guzzle 6 compatibility profile and uses Composer's `--no-security-blocking` only in that profile. It is not a deploy recommendation. The package root is audited without bypasses, and production applications should upgrade Guzzle, AWS SDK, and MongoDB dependencies independently of this instrumentation.
