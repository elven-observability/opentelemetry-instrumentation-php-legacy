<?php

namespace Elven\Observability\PhpLegacy\Tests\Unit;

use Elven\Observability\PhpLegacy\Attribution\TrafficSourceResolver;
use Elven\Observability\PhpLegacy\Config\EnvConfigResolver;
use Elven\Observability\PhpLegacy\Privacy\AttributeRedactor;
use Elven\Observability\PhpLegacy\Tests\Support\Env;
use PHPUnit\Framework\TestCase;

final class TrafficSourceResolverTest extends TestCase
{
    protected function setUp(): void
    {
        Env::reset();
    }

    public function testResolvesKnownMetasearchSourcesFromRequest(): void
    {
        self::assertSame(array(
            'traffic_source' => 'skyscanner',
            'traffic_channel' => 'metasearch',
        ), TrafficSourceResolver::attributesFromRequest(array('utmSource' => 'Sky Scanner')));

        self::assertSame(array(
            'traffic_source' => 'google_flights',
            'traffic_channel' => 'metasearch',
        ), TrafficSourceResolver::attributesFromRequest(array('traffic_source' => 'Google Flights')));
    }

    public function testResolvesOwnedFrontAndBackend(): void
    {
        self::assertSame(array(
            'traffic_source' => 'front',
            'traffic_channel' => 'owned',
        ), TrafficSourceResolver::attributesFromRequest(array('utm_source' => 'site')));

        self::assertSame(array(
            'traffic_source' => 'backend',
            'traffic_channel' => 'backoffice',
        ), TrafficSourceResolver::attributesFromRequest(array('utm_source' => 'TelaConsultor')));
    }

    public function testDoesNotExposeHighCardinalityPartnerIdentifiers(): void
    {
        self::assertSame(array(
            'traffic_source' => 'unknown',
            'traffic_channel' => 'unknown',
        ), TrafficSourceResolver::attributesFromRequest(array(
            'partnerRedirectId' => '0123456789abcdef0123456789abcdef',
        )));

        self::assertSame('other', TrafficSourceResolver::normalizeSource('0123456789abcdef0123456789abcdef'));
    }

    public function testResolvesMetasearchFromSafePresenceOnlySignals(): void
    {
        self::assertSame(array(
            'traffic_source' => 'skyscanner',
            'traffic_channel' => 'metasearch',
        ), TrafficSourceResolver::attributesFromRequest(array(
            'skyScannerCode' => 'dynamic-code-not-exported',
        )));

        self::assertSame(array(
            'traffic_source' => 'skyscanner',
            'traffic_channel' => 'metasearch',
        ), TrafficSourceResolver::attributesFromRequest(array(), array(
            'REQUEST_URI' => '/rest/v2/aerial/search?skyScannerCode=dynamic-code-not-exported',
        )));

        self::assertSame(array(
            'traffic_source' => 'google',
            'traffic_channel' => 'paid',
        ), TrafficSourceResolver::attributesFromRequest(array(), array(
            'REQUEST_URI' => '/rest/v2/aerial/search?gclid=dynamic-click-id-not-exported',
        )));
    }

    public function testFallsBackToQueryStringAndHeaders(): void
    {
        self::assertSame(array(
            'traffic_source' => 'mundi',
            'traffic_channel' => 'metasearch',
        ), TrafficSourceResolver::attributesFromRequest(array(), array(
            'REQUEST_URI' => '/rest/v2/aerial/search?utm_source=mundi&utm_medium=metasearch',
        )));

        self::assertSame(array(
            'traffic_source' => 'kayak',
            'traffic_channel' => 'metasearch',
        ), TrafficSourceResolver::attributesFromRequest(array(), array(
            'HTTP_X_TRAFFIC_SOURCE' => 'kayak',
        )));
    }

    public function testTrustedRequestValuesWinOverSpoofableHeadersAndUnknownFallbacks(): void
    {
        self::assertSame(array(
            'traffic_source' => 'skyscanner',
            'traffic_channel' => 'metasearch',
        ), TrafficSourceResolver::attributesFromRequest(array(
            'utmSource' => 'skyscanner',
        ), array(
            'HTTP_X_TRAFFIC_SOURCE' => 'unknown-partner-123',
        )));

        self::assertSame(array(
            'traffic_source' => 'google',
            'traffic_channel' => 'paid',
        ), TrafficSourceResolver::attributesFromRequest(array(), array(
            'HTTP_REFERER' => 'https://www.google.com/search?q=legacy',
            'HTTP_X_TRAFFIC_SOURCE' => str_repeat('a', 64),
        )));
    }

    public function testNormalizesExplicitMetasearchChannel(): void
    {
        self::assertSame(array(
            'traffic_source' => 'other',
            'traffic_channel' => 'metasearch',
        ), TrafficSourceResolver::attributesFromSource('unmapped_partner', 'metabuscador'));
    }

    /**
     * A metasearch partner IS the channel. Measured in homolog-12 on 2026-08-11:
     * the SAME skyscanner journey reported traffic_channel=metasearch on five
     * routes and traffic_channel=paid on /rest/v2/ticket/book -- the one route
     * whose request body carries the marketing fields. A utm_medium in the
     * payload was outranking the intrinsic channel, moving the SALE out of the
     * channel that produced it and breaking conversion-by-channel exactly at the
     * step the business cares about most.
     */
    public function testMetasearchChannelSurvivesAMarketingMediumInThePayload(): void
    {
        foreach (array('cpc', 'organic', 'social', 'email') as $medium) {
            self::assertSame(array(
                'traffic_source' => 'skyscanner',
                'traffic_channel' => 'metasearch',
            ), TrafficSourceResolver::attributesFromRequest(array(
                'traffic_source' => 'skyscanner',
                'utm_medium' => $medium,
            ), array()), 'utm_medium=' . $medium . ' must not outrank the intrinsic metasearch channel');
        }
    }

    public function testMetasearchChannelSurvivesAnExplicitChannelFromQueryOrHeader(): void
    {
        self::assertSame(array(
            'traffic_source' => 'kayak',
            'traffic_channel' => 'metasearch',
        ), TrafficSourceResolver::attributesFromRequest(array(
            'traffic_source' => 'kayak',
        ), array(
            'HTTP_X_TRAFFIC_CHANNEL' => 'cpc',
        )));

        self::assertSame(array(
            'traffic_source' => 'google_flights',
            'traffic_channel' => 'metasearch',
        ), TrafficSourceResolver::attributesFromRequest(array(
            'traffic_source' => 'google_flights',
        ), array(
            'QUERY_STRING' => 'utm_medium=cpc',
        )));
    }

    /**
     * Blast-radius control for the rule above: for a NON-metasearch source the
     * explicit medium must keep winning, exactly as before.
     */
    public function testNonMetasearchSourceStillHonoursTheExplicitMedium(): void
    {
        self::assertSame(array(
            'traffic_source' => 'google',
            'traffic_channel' => 'organic',
        ), TrafficSourceResolver::attributesFromRequest(array(
            'traffic_source' => 'google',
            'utm_medium' => 'organic',
        ), array()));

        self::assertSame(array(
            'traffic_source' => 'front',
            'traffic_channel' => 'paid',
        ), TrafficSourceResolver::attributesFromRequest(array(
            'traffic_source' => 'front',
            'utm_medium' => 'cpc',
        ), array()));

        self::assertSame(array(
            'traffic_source' => 'front',
            'traffic_channel' => 'owned',
        ), TrafficSourceResolver::attributesFromRequest(array(
            'traffic_source' => 'front',
        ), array()));
    }

    /**
     * Sources a consumer ALREADY emits and this library used to fold into
     * `other`. Measured in zupper-api (2026-09-15): its request context resolves
     * `voelivre`, `voopter` and `melhoresdestinos` (front header
     * X-Metasearch-Engine) and the social networks (forwarded referer / utm).
     * The SERVER span kept the name -- span attributes are not normalized -- but
     * every metric label and the reservation worker (which re-normalizes the
     * baggage) read `other`, and `social` as channel read `unknown`. A Tempo
     * search for `traffic_source=voelivre` found the request and not the sale.
     */
    public function testMetasearchEnginesTheConsumerEmitsKeepTheirNameAndIntrinsicChannel(): void
    {
        foreach (array('voelivre', 'voopter', 'melhoresdestinos') as $engine) {
            self::assertSame($engine, TrafficSourceResolver::normalizeSource($engine));
            self::assertSame(array(
                'traffic_source' => $engine,
                'traffic_channel' => 'metasearch',
            ), TrafficSourceResolver::attributesFromSource($engine));
            // Intrinsic, like every other metasearch partner: a marketing medium
            // in the payload does not move the sale out of the channel.
            self::assertSame(array(
                'traffic_source' => $engine,
                'traffic_channel' => 'metasearch',
            ), TrafficSourceResolver::attributesFromRequest(array(
                'utm_source' => $engine,
                'utm_medium' => 'cpc',
            )));
        }
        self::assertSame('melhoresdestinos', TrafficSourceResolver::normalizeSource('Melhores Destinos'));
        self::assertSame('voelivre', TrafficSourceResolver::normalizeSource('Voe Livre'));
    }

    public function testSocialNetworksTheConsumerEmitsKeepTheirNameOnTheSocialChannel(): void
    {
        foreach (array('instagram', 'facebook', 'twitter', 'tiktok', 'pinterest', 'youtube', 'linkedin', 'whatsapp') as $network) {
            self::assertSame($network, TrafficSourceResolver::normalizeSource($network));
            self::assertSame(array(
                'traffic_source' => $network,
                'traffic_channel' => 'social',
            ), TrafficSourceResolver::attributesFromSource($network));
        }
        self::assertSame('social', TrafficSourceResolver::normalizeChannel('social'));
        // A social network is not intrinsic: paid social stays paid.
        self::assertSame(array(
            'traffic_source' => 'instagram',
            'traffic_channel' => 'paid',
        ), TrafficSourceResolver::attributesFromRequest(array(
            'utm_source' => 'instagram',
            'utm_medium' => 'cpc',
        )));
    }

    public function testOrganicSearchReferralAndEmailAreFirstClass(): void
    {
        self::assertSame(array('traffic_source' => 'organic_search', 'traffic_channel' => 'organic'), TrafficSourceResolver::attributesFromSource('organic_search'));
        self::assertSame(array('traffic_source' => 'organic_search', 'traffic_channel' => 'organic'), TrafficSourceResolver::attributesFromSource('organic'));
        self::assertSame(array('traffic_source' => 'bing', 'traffic_channel' => 'organic'), TrafficSourceResolver::attributesFromSource('bing'));
        self::assertSame(array('traffic_source' => 'referral', 'traffic_channel' => 'referral'), TrafficSourceResolver::attributesFromSource('referral'));
        self::assertSame('email', TrafficSourceResolver::normalizeChannel('email'));
        self::assertSame('email', TrafficSourceResolver::normalizeChannel('newsletter'));
        self::assertSame('referral', TrafficSourceResolver::normalizeChannel('referral'));
    }

    /**
     * Negative controls: the vocabulary grew by exact tokens, not by substrings.
     * A value that merely CONTAINS a known name is still `other`, and an invented
     * channel is still `unknown`.
     */
    public function testVocabularyGrowthIsExactAndStillBounded(): void
    {
        foreach (array('fonte_inventada', 'notinstagram', 'instagram_ads_campaign_x', 'facebook-scam', 'voelivre2', 'referral_program_abc') as $value) {
            self::assertSame('other', TrafficSourceResolver::normalizeSource($value), $value);
        }
        foreach (array('canal_inventado', 'sociall', 'push') as $value) {
            self::assertSame('unknown', TrafficSourceResolver::normalizeChannel($value), $value);
        }
    }

    /**
     * Cardinality tripwire. Every metric point of a consumer carries
     * traffic_source x traffic_channel x is_bot, so these two lists ARE the
     * multiplier. Growing them must be a conscious change to this test.
     */
    public function testKnownVocabularyIsClosedAndRoundTrips(): void
    {
        $sources = TrafficSourceResolver::knownSources();
        $channels = TrafficSourceResolver::knownChannels();

        self::assertCount(29, $sources);
        self::assertCount(10, $channels);
        self::assertSame($sources, array_values(array_unique($sources)));
        foreach ($sources as $source) {
            self::assertSame($source, TrafficSourceResolver::normalizeSource($source), 'source ' . $source);
        }
        foreach ($channels as $channel) {
            self::assertSame($channel, TrafficSourceResolver::normalizeChannel($channel), 'channel ' . $channel);
        }
    }

    /**
     * The metric label path is where the names were lost: AttributeRedactor
     * normalizes traffic_source/traffic_channel with this resolver on EVERY point
     * (request histogram, dependency histogram, consumer counters).
     */
    public function testMetricLabelsKeepTheNewVocabulary(): void
    {
        $redactor = new AttributeRedactor(EnvConfigResolver::resolve());
        $allowed = array('traffic_source', 'traffic_channel', 'is_bot');

        self::assertSame(
            array('traffic_source' => 'voelivre', 'traffic_channel' => 'metasearch', 'is_bot' => 'false'),
            $redactor->redactMetricLabels(array('traffic_source' => 'voelivre', 'traffic_channel' => 'metasearch', 'is_bot' => 'false'), $allowed)
        );
        self::assertSame(
            array('traffic_source' => 'instagram', 'traffic_channel' => 'social', 'is_bot' => 'true'),
            $redactor->redactMetricLabels(array('traffic_source' => 'instagram', 'traffic_channel' => 'social', 'is_bot' => 'true'), $allowed)
        );
        // Forged values keep collapsing.
        self::assertSame(
            array('traffic_source' => 'other', 'traffic_channel' => 'unknown'),
            $redactor->redactMetricLabels(array('traffic_source' => 'fonte-inventada', 'traffic_channel' => 'canal-inventado'), $allowed)
        );
    }

    /**
     * The vocabulary growth must not reorder detection: a social/organic
     * utm_source used to normalize to `other` (fallback) and lose to
     * skyScannerCode, gclid, the referer and X-Traffic-Source.
     */
    public function testWeakSourcesKeepTheOldFallbackPrecedence(): void
    {
        self::assertSame('skyscanner', TrafficSourceResolver::attributesFromRequest(array('utm_source' => 'instagram', 'skyScannerCode' => 'x'))['traffic_source']);
        self::assertSame('google', TrafficSourceResolver::attributesFromRequest(array('utm_source' => 'facebook'), array('QUERY_STRING' => 'gclid=x'))['traffic_source']);
        self::assertSame('kayak', TrafficSourceResolver::attributesFromRequest(array('utm_source' => 'bing'), array('HTTP_REFERER' => 'https://www.kayak.com.br/x'))['traffic_source']);
        self::assertSame('skyscanner', TrafficSourceResolver::attributesFromRequest(array('source' => 'facebook'), array('HTTP_X_TRAFFIC_SOURCE' => 'skyscanner'))['traffic_source']);
        // no stronger signal: the weak name is kept (the point of the vocabulary change)
        self::assertSame(array('traffic_source' => 'instagram', 'traffic_channel' => 'paid'), TrafficSourceResolver::attributesFromRequest(array('utm_source' => 'instagram', 'utm_medium' => 'cpc')));
        // weak beats other, first weak wins
        self::assertSame('facebook', TrafficSourceResolver::attributesFromRequest(array('utm_source' => 'facebook'), array('HTTP_X_TRAFFIC_SOURCE' => 'twitter'))['traffic_source']);
    }
}
