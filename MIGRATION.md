# Optional 0.8 RC Migration

The intended release is `0.8.0-rc.1`, not a transparent `0.7.3` patch. It is not published merely because this file exists. PHP remains `>=7.3.13`; that compatibility does not make PHP7.3/7.4 upstream-supported or secure. Existing stable `^0.7` Composer constraints do not select this new minor prerelease. Opt in only after the immutable tag and required profile are qualified.

## Resource And Delivery Policy

The new trace/log/metric admission policy shares an 8MiB logical budget and caps each trace/log record at64KiB. JSON traversal and transmitted payloads are bounded; excessive/new data is dropped. Static metric names and bounded dimensions are required. Normal safe dimensions remain, but not every historical unbounded series is retained. Logical-byte limits are not process RSS limits or a durable spool.

Flush/shutdown is synchronous with one cooperative deadline shared by signals. Very short deadlines, serialization cost, full queues, unsupported framing and collector failures can lose telemetry; a successful application result does not prove delivery. Review drop behavior and decoded collector data, not only HTTP path counts. Do not claim default200ms is a universal latency guarantee or that a failed flush will replay every measurement.

## Network And Adapters

The built-in exporter keeps verified TLS, redirect refusal, bounded responses and fail-open application behavior for handled failures. OS DNS is synchronous in the compatibility profile; strict resolver mode is explicit opt-in. Direct/proxy/HTTPS CONNECT/auth/custom-CA cases were tested, not every proxy, compressed response, framework or operating system. Arbitrary application transport customizations are not automatically carried into the owned exporter.

Guzzle remains optional. Its historical adapter call shapes are retained, but Guzzle6 is not security-qualified and must not be restored to make an old integration test green. The reviewed development profile uses patched Guzzle7.16.1; no forced Guzzle8/Monolog3 major is imposed on PHP7.3 consumers. Slim2 requires explicit wiring; no Laravel provider or zero-code interception is added to the legacy core.

## Adoption

Confirm the release tag, install from Git/VCS without replacing the old stable lock, audit the resolved graph and qualify all three decoded signals, normal labels, failure handling and worker lifecycle in staging. Keep `^0.7` consumers unchanged until migration is accepted. Publishing does not deploy to customer applications, recover dropped telemetry or confer security support on EOL runtimes.
