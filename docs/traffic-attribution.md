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
- `voelivre`
- `voopter`
- `melhoresdestinos`
- `metasearch_other`
- `instagram`, `facebook`, `twitter`, `tiktok`, `pinterest`, `youtube`, `linkedin`, `whatsapp` (channel `social`)
- `organic_search`, `bing` (channel `organic`)
- `referral` (channel `referral`)
- `backend`
- `other`
- `unknown`

`TrafficSourceResolver::knownSources()` returns the full closed list (29 values).

Recommended `traffic_channel` values:

- `owned`
- `metasearch`
- `paid`
- `organic`
- `partner`
- `backoffice`
- `social`
- `email`
- `referral`
- `unknown`

`TrafficSourceResolver::knownChannels()` returns the full closed list (10 values).

Unknown or high-cardinality-looking values are collapsed to `other` or `unknown`. Matching is by exact token: `instagram_ads_campaign_x` is `other`.

A metasearch source always has channel `metasearch`, whatever medium travels with it. Any other source honours an explicit medium (`utm_medium=cpc` on `instagram` is `paid`).

## Cardinality

Every metric point carries `traffic_source` x `traffic_channel` x `is_bot`. The domain is 29 x 10 pairs x 4 `is_bot` values; what a deployment actually emits is much smaller and must be measured (`count by (traffic_source, traffic_channel, is_bot)` over the request histogram).

## Bot classification

`BotClassifier` maps the User-Agent to `client.is_bot` and `bot.category` (`search_engine`, `social`, `seo`, `monitoring`, `tooling`, `generic_bot`, `none`) on the SERVER span. Only `is_bot` is a metric label. `is_bot` and `bot.category` both ride the W3C baggage, set after the inbound baggage is merged, so a client-sent value is overwritten. Mobile app HTTP stacks (`okhttp/...`, `<App>/1 CFNetwork/...`) are human.

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
