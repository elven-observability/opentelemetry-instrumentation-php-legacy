# Traffic Attribution

Use traffic attribution when the application needs to split metrics by safe commercial origin, such as own frontend, metasearch, paid search, partner, or backoffice.

The library only accepts bounded labels:

- `traffic_source`
- `traffic_channel`

Do not use campaign names, redirect ids, order ids, session ids, click ids, full referrers, or partner payloads as metric labels.

## Canonical Values

Recommended `traffic_source` values:

- `front`
- `mobile_app`
- `google`
- `google_flights`
- `skyscanner`
- `mundi`
- `kayak`
- `viajala`
- `wego`
- `momondo`
- `partner_offers`
- `backend`
- `metasearch_other`
- `other`
- `unknown`

Recommended `traffic_channel` values:

- `owned`
- `metasearch`
- `paid`
- `organic`
- `partner`
- `backoffice`
- `unknown`

Unknown or high-cardinality-looking values are collapsed to `other` or `unknown`.

## Usage

`HttpServerInstrumentation` automatically derives baseline traffic labels from safe request globals such as `utm_source`, query string, and referrer. For legacy apps where the real source is only available after route parsing, set request attributes early inside the server span and before business/dependency metrics are recorded:

```php
use Elven\Observability\PhpLegacy\Attribution\TrafficSourceResolver;
use Elven\Observability\PhpLegacy\Observability;

$traffic = TrafficSourceResolver::attributesFromRequest($requestData, $_SERVER);

Observability::metrics()->setRequestAttributes($traffic);
$span->setAttributes($traffic);
```

All metrics emitted after `setRequestAttributes()` inherit `traffic_source` and `traffic_channel`.

For explicit values:

```php
Observability::metrics()->setRequestAttributes(
    TrafficSourceResolver::attributesFromSource('skyscanner')
);
```

Request attributes are cleared during `shutdown()` so long-running workers do not leak one request's origin into the next request.
