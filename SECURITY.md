# Security Policy

## Supported Versions

The initial supported line is `0.1.x` for PHP `>=7.3.13`.

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
- DB statements are redacted by default.
- OTLP logs export is not implemented in v1.
- App-side Elven tokens are not required when exporting to a customer Collector.
