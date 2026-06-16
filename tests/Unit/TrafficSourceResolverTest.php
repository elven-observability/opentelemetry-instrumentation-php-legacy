<?php

namespace Elven\Observability\PhpLegacy\Tests\Unit;

use Elven\Observability\PhpLegacy\Attribution\TrafficSourceResolver;
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
}
