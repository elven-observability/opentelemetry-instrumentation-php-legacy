<?php

namespace Elven\Observability\PhpLegacy\Instrumentation;

/**
 * Classifies a request's User-Agent as bot/crawler vs human, into a small,
 * BOUNDED set of categories. This exists so dashboards can split demand by
 * human vs automated traffic (e.g. the ~72% bot share seen on some routes)
 * WITHOUT ever putting the raw, high-cardinality User-Agent string into a
 * metric label.
 *
 * Design rules:
 *  - Output is low cardinality: is_bot is boolean; category is one of a fixed
 *    enum. The raw UA is never returned here (the HTTP span keeps a truncated
 *    user_agent.original separately, which is a span attribute, not a metric
 *    label).
 *  - Ultra-defensive: any non-string / empty input returns the human default.
 *    Telemetry classification must never throw on the request path.
 *  - Conservative: an empty/missing UA is treated as human ('none'), not bot,
 *    to avoid inflating the bot share on clients that legitimately omit it.
 *
 * PHP 7.3 compatible (no typed properties, arrow fns, or union types).
 */
final class BotClassifier
{
    const CATEGORY_NONE = 'none';

    /**
     * Ordered category => case-insensitive regex of UA tokens. The first match
     * wins, so the more specific/known categories are listed before the generic
     * bot fallback. Patterns are simple alternations (cheap to evaluate).
     *
     * @var array<string,string>
     */
    private static $signatures = array(
        // adsbot-google (also -mobile), mediapartners-google, googleother and
        // apis-google are Google crawlers by their published User-Agents: AdsBot
        // only reached generic_bot through `bot\b`, the other three were human.
        'search_engine' => '/googlebot|google-inspectiontool|storebot-google|adsbot-google|mediapartners-google|googleother|apis-google|bingbot|adidxbot|slurp|duckduckbot|baiduspider|yandex(bot|images|mobilebot)|sogou|exabot|applebot|petalbot|gptbot|oai-searchbot|chatgpt-user|perplexitybot|claudebot|amazonbot/',
        'social' => '/facebookexternalhit|facebot|twitterbot|linkedinbot|whatsapp|telegrambot|slackbot|slack-imgproxy|discordbot|pinterest(bot)?|redditbot|skypeuripreview/',
        'seo' => '/ahrefsbot|semrushbot|mj12bot|dotbot|rogerbot|screaming\s?frog|seokicks|sistrix|dataforseo|blexbot|barkrowler/',
        // kube-probe (Kubernetes liveness/readiness), Blackbox Exporter
        // (Prometheus probing) and SyntheticMonitor: all three seen on a consumer's
        // production API (zupper-api, 2026-09-16). The first two were counted as
        // human demand; SyntheticMonitor only reached generic_bot via `monitor`.
        'monitoring' => '/pingdom|uptimerobot|statuscake|site24x7|datadog|newrelicpinger|gtmetrix|lighthouse|pagespeed|chrome-lighthouse|catchpoint|kube-probe|blackbox exporter|syntheticmonitor|uptime-guardian|uptime-kuma/',
        // `okhttp` stays here on purpose. It is the default User-Agent of Android
        // apps built on OkHttp, but also of any JVM/Kotlin script, and the User-Agent
        // alone cannot tell them apart. A consumer that knows its own app (a
        // first-party credential plus the exact `okhttp/<version>` token) should
        // reclassify with that context; see docs/traffic-attribution.md.
        // apache-cxf: Java web-services client, same family as apache-httpclient;
        // seen on the same production API calling the search endpoint.
        'tooling' => '/python-requests|python-urllib|aiohttp|curl\/|wget|scrapy|go-http-client|java\/|jakarta|apache-httpclient|apache-cxf|libwww|okhttp|axios|node-fetch|got\s|guzzle|headlesschrome|phantomjs|puppeteer|playwright|selenium/',
        'generic_bot' => '/bot\b|crawler|spider|crawl|fetcher|archiver|scraper|monitor/',
    );

    /**
     * @param mixed $userAgent
     * @return array{is_bot:bool,category:string}
     */
    public static function classify($userAgent)
    {
        $result = array('is_bot' => false, 'category' => self::CATEGORY_NONE);

        if (!is_string($userAgent) || $userAgent === '') {
            return $result;
        }

        $ua = strtolower($userAgent);
        foreach (self::$signatures as $category => $pattern) {
            if (preg_match($pattern, $ua) === 1) {
                $result['is_bot'] = true;
                $result['category'] = $category;
                return $result;
            }
        }

        return $result;
    }

    /**
     * Convenience: the span attribute set for a request (low cardinality).
     * is_bot is emitted as a string so it serializes consistently as a span
     * attribute / metric label across exporters.
     *
     * @param mixed $userAgent
     * @return array<string,string>
     */
    public static function spanAttributes($userAgent)
    {
        $c = self::classify($userAgent);
        return array(
            'client.is_bot' => $c['is_bot'] ? 'true' : 'false',
            'bot.category' => $c['category'],
        );
    }

    /**
     * The metric label set for a request. Intentionally only is_bot (2 values)
     * to keep request-metric cardinality flat; the richer bot.category lives on
     * the span only.
     *
     * @param mixed $userAgent
     * @return array<string,string>
     */
    public static function metricAttributes($userAgent)
    {
        $c = self::classify($userAgent);
        return array(
            'is_bot' => $c['is_bot'] ? 'true' : 'false',
        );
    }
}
