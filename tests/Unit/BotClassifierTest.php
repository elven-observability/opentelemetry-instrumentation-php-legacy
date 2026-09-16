<?php

namespace Elven\Observability\PhpLegacy\Tests\Unit;

use Elven\Observability\PhpLegacy\Instrumentation\BotClassifier;
use PHPUnit\Framework\TestCase;

final class BotClassifierTest extends TestCase
{
    /**
     * @dataProvider userAgentProvider
     */
    public function testClassifyMapsUserAgentToBoundedCategory($ua, bool $isBot, string $category): void
    {
        $result = BotClassifier::classify($ua);
        self::assertSame($isBot, $result['is_bot'], 'is_bot for: ' . var_export($ua, true));
        self::assertSame($category, $result['category'], 'category for: ' . var_export($ua, true));
    }

    public function userAgentProvider(): array
    {
        return array(
            'googlebot' => array('Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', true, 'search_engine'),
            'bingbot' => array('Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)', true, 'search_engine'),
            'gptbot' => array('Mozilla/5.0 (compatible; GPTBot/1.0; +https://openai.com/gptbot)', true, 'search_engine'),
            'facebook' => array('facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)', true, 'social'),
            'whatsapp' => array('WhatsApp/2.23', true, 'social'),
            'ahrefs' => array('Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)', true, 'seo'),
            'uptimerobot' => array('Mozilla/5.0+(compatible; UptimeRobot/2.0; http://www.uptimerobot.com/)', true, 'monitoring'),
            'curl' => array('curl/7.68.0', true, 'tooling'),
            'python' => array('python-requests/2.31.0', true, 'tooling'),
            'go-http' => array('Go-http-client/2.0', true, 'tooling'),
            'generic-spider' => array('SomeRandomSpider/1.0 crawler', true, 'generic_bot'),
            'chrome-human' => array('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36', false, 'none'),
            'iphone-safari' => array('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1', false, 'none'),
            'empty' => array('', false, 'none'),

            // --- Mobile app HTTP stacks. ---
            // okhttp/<v> is the default User-Agent of Android apps built on OkHttp,
            // and of JVM/Kotlin scripts too: from the User-Agent alone it stays
            // tooling. A consumer reclassifies its own app with context (credential).
            'okhttp-stays-tooling' => array('okhttp/4.9.2', true, 'tooling'),
            // Negative control: the iOS build of the same app is human.
            'ios-app-cfnetwork' => array('ZupperApp/1 CFNetwork/3860.700.2 Darwin/25.6.0', false, 'none'),

            // --- Probes and synthetic monitors seen in production (zupper-api, 2026-09-16). ---
            // kube-probe and Blackbox Exporter were counted as HUMAN demand.
            'kube-probe' => array('kube-probe/1.28', true, 'monitoring'),
            'blackbox-exporter' => array('Blackbox Exporter/v1.16.1', true, 'monitoring'),
            // Already a bot through the generic `monitor` token; the category was wrong.
            'synthetic-monitor' => array('Mozilla/5.0 (compatible; SyntheticMonitor/1.0)', true, 'monitoring'),

            // --- Server-side HTTP client framework seen in production (2026-09-16). ---
            // Same family as Apache-HttpClient, which was already tooling.
            'apache-cxf' => array('Apache-CXF/3.6.2', true, 'tooling'),

            // --- Google crawlers, User-Agent strings as published by Google. ---
            // AdsBot was a bot through `bot\b` but landed in generic_bot; the other
            // three were not detected at all.
            'adsbot-google' => array('AdsBot-Google (+http://www.google.com/adsbot.html)', true, 'search_engine'),
            'adsbot-google-mobile' => array('Mozilla/5.0 (iPhone; CPU iPhone OS 14_7_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/14.1.2 Mobile/15E148 Safari/604.1 (compatible; AdsBot-Google-Mobile; +http://www.google.com/mobile/adsbot.html)', true, 'search_engine'),
            'mediapartners-google' => array('Mediapartners-Google', true, 'search_engine'),
            'googleother' => array('Mozilla/5.0 (compatible; GoogleOther)', true, 'search_engine'),
            'apis-google' => array('APIs-Google (+https://developers.google.com/webmasters/APIs-Google.html)', true, 'search_engine'),

            // --- Negative controls for the new tokens: substrings a browser can carry. ---
            'chrome-on-google-tv' => array('Mozilla/5.0 (Linux; Android 12; Google TV) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36', false, 'none'),

            'non-string' => array(null, false, 'none'),
            'array-input' => array(array('x'), false, 'none'),
        );
    }

    public function testSpanAttributesExposeIsBotAndCategory(): void
    {
        $attrs = BotClassifier::spanAttributes('Googlebot/2.1');
        self::assertSame('true', $attrs['client.is_bot']);
        self::assertSame('search_engine', $attrs['bot.category']);

        $human = BotClassifier::spanAttributes('Mozilla/5.0 Chrome/124.0');
        self::assertSame('false', $human['client.is_bot']);
        self::assertSame('none', $human['bot.category']);
    }

    public function testMetricAttributesAreLowCardinalityIsBotOnly(): void
    {
        $bot = BotClassifier::metricAttributes('AhrefsBot/7.0');
        self::assertSame(array('is_bot' => 'true'), $bot, 'metric labels must be is_bot only (no category, no raw UA)');

        $human = BotClassifier::metricAttributes('Mozilla/5.0 Chrome/124.0');
        self::assertSame(array('is_bot' => 'false'), $human);
    }
}
