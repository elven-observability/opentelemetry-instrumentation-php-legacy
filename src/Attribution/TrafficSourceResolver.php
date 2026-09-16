<?php

namespace Elven\Observability\PhpLegacy\Attribution;

final class TrafficSourceResolver
{
    const MAX_VALUE_BYTES = 256;
    const MAX_QUERY_BYTES = 4096;

    private static $metasearchSources = array(
        'google_flights',
        'skyscanner',
        'mundi',
        'kayak',
        'viajala',
        'wego',
        'momondo',
        'partner_offers',
        'metasearch_other',
        // Engines a consumer's front classifies and forwards (zupper-api,
        // X-Metasearch-Engine). Until this change they were emitted by the consumer
        // and folded into `other` here: the SERVER span said `voelivre`, every
        // metric label and the worker said `other`.
        'voelivre',
        'voopter',
        'melhoresdestinos',
    );

    /**
     * Social networks, always channel `social` unless an explicit medium says
     * otherwise (paid social is `paid`). Exact tokens only: the consumer maps a
     * referer HOST to these names with anchored matching, and a substring match
     * here would reopen the spoofing it closed.
     */
    private static $socialSources = array(
        'instagram',
        'facebook',
        'twitter',
        'tiktok',
        'pinterest',
        'youtube',
        'linkedin',
        'whatsapp',
    );

    /** Organic search, channel `organic`. */
    private static $organicSources = array(
        'organic_search',
        'bing',
    );

    /** Sources outside the families above that always map to themselves. */
    private static $baseSources = array(
        'unknown',
        'other',
        'google',
        'front',
        'mobile_app',
        'backend',
        'referral',
    );

    /**
     * Cardinality: every metric point of a consumer carries
     * traffic_source x traffic_channel x is_bot. {@see self::knownSources()} and
     * this list are that multiplier; the unit test pins both sizes.
     */
    private static $knownChannels = array(
        'owned',
        'metasearch',
        'paid',
        'organic',
        'partner',
        'backoffice',
        'social',
        'email',
        'referral',
        'unknown',
    );

    private function __construct()
    {
    }

    public static function attributesFromRequest(array $request, array $server = array())
    {
        $source = self::detectSource($request, $server);
        return self::attributesFromSource($source, self::detectChannel($request, $server, $source));
    }

    public static function attributesFromSource($source, $channel = null)
    {
        $source = self::normalizeSource($source);
        $channel = $channel === null ? self::channelForSource($source) : self::normalizeChannel($channel);
        if ($channel === 'unknown') {
            $channel = self::channelForSource($source);
        }

        return array(
            'traffic_source' => $source,
            'traffic_channel' => $channel,
        );
    }

    /**
     * Every value {@see self::normalizeSource()} can return.
     *
     * @return string[]
     */
    public static function knownSources()
    {
        return array_values(array_unique(array_merge(
            self::$baseSources,
            self::$metasearchSources,
            self::$socialSources,
            self::$organicSources
        )));
    }

    /**
     * Every value {@see self::normalizeChannel()} can return.
     *
     * @return string[]
     */
    public static function knownChannels()
    {
        return self::$knownChannels;
    }

    public static function normalizeSource($source)
    {
        if ($source === null || $source === false) {
            return 'unknown';
        }

        $value = self::normalizeToken($source);
        if ($value === '') {
            return 'unknown';
        }
        if ($value === 'unknown' || $value === 'not_set' || $value === 'none' || $value === 'null') {
            return 'unknown';
        }

        if (preg_match('/^[0-9a-f]{16,64}$/i', $value) === 1) {
            return 'other';
        }
        if (preg_match('/^[a-z0-9_]{24,}$/', $value) === 1) {
            return 'other';
        }

        if (strpos($value, 'google_flight') !== false || strpos($value, 'googleflights') !== false) {
            return 'google_flights';
        }
        if ($value === 'google' || $value === 'google_ads' || $value === 'googleadwords') {
            return 'google';
        }
        if ($value === 'sky_scanner' || $value === 'skyscanner' || $value === 'sky') {
            return 'skyscanner';
        }
        if (in_array($value, array('partner_offers', 'partner_offer', 'offers', 'ofertas', 'metasearch_offers'), true)) {
            return 'partner_offers';
        }
        if (in_array($value, array('site', 'direct', '_direct_', 'front', 'frontend', 'web', 'website'), true)) {
            return 'front';
        }
        if (in_array($value, array('mobile', 'mobile_app', 'app', 'android', 'ios'), true)) {
            return 'mobile_app';
        }
        if (in_array($value, array('backend', 'backoffice', 'admin', 'tela_consultor', 'telaconsultor'), true)) {
            return 'backend';
        }
        if (in_array($value, self::$metasearchSources, true)) {
            return $value;
        }
        if ($value === 'meta' || $value === 'metasearch' || $value === 'metabuscador') {
            return 'metasearch_other';
        }
        if ($value === 'voe_livre') {
            return 'voelivre';
        }
        if ($value === 'melhores_destinos') {
            return 'melhoresdestinos';
        }
        if (in_array($value, self::$socialSources, true) || in_array($value, self::$organicSources, true)) {
            return $value;
        }
        if ($value === 'organic' || $value === 'seo') {
            return 'organic_search';
        }
        if ($value === 'referral') {
            return 'referral';
        }

        return 'other';
    }

    public static function normalizeChannel($channel)
    {
        $value = self::normalizeToken($channel);
        if ($value === '') {
            return 'unknown';
        }
        if ($value === 'cpc' || $value === 'ppc' || $value === 'paid_search' || $value === 'ads') {
            return 'paid';
        }
        if ($value === 'meta' || $value === 'metasearcher' || $value === 'metabuscador') {
            return 'metasearch';
        }
        if ($value === 'organic_search' || $value === 'seo') {
            return 'organic';
        }
        if ($value === 'front' || $value === 'site' || $value === 'web' || $value === 'direct') {
            return 'owned';
        }
        if ($value === 'backoffice' || $value === 'backend') {
            return 'backoffice';
        }
        if ($value === 'newsletter') {
            return 'email';
        }
        return in_array($value, self::$knownChannels, true) ? $value : 'unknown';
    }

    private static function detectSource(array $request, array $server)
    {
        $fallback = null;
        foreach (self::sourceCandidates($request, $server) as $candidate) {
            $source = self::normalizeSource($candidate);
            if ($source !== 'unknown' && $source !== 'other') {
                return $source;
            }
            if ($source === 'other') {
                $fallback = 'other';
            }
        }
        $hasSkyscannerCode = self::value($request, array(
            'skyScannerCode',
            'skyscannerCode',
            'sky_scanner_code',
            'SkyScannerCode',
        )) !== null || self::queryValue($server, array(
            'skyScannerCode',
            'skyscannerCode',
            'sky_scanner_code',
            'SkyScannerCode',
        )) !== null;
        if ($hasSkyscannerCode) {
            return 'skyscanner';
        }
        $hasGoogleClickId = self::value($request, array('gclid', 'gbraid', 'wbraid')) !== null
            || self::queryValue($server, array('gclid', 'gbraid', 'wbraid')) !== null;
        if ($hasGoogleClickId) {
            return 'google';
        }
        return $fallback ?: 'unknown';
    }

    private static function detectChannel(array $request, array $server, $source)
    {
        // A metasearch partner IS the channel: 'metasearch' is intrinsic to the
        // source and must never be overridden by a marketing medium travelling in
        // the payload, the query string or a header. Measured in production
        // (2026-08-11): the SAME skyscanner journey reported channel=metasearch on
        // five routes and channel=paid on /rest/v2/ticket/book -- the one route
        // whose body carries the marketing fields -- because utm_medium=cpc won
        // here. That silently moves the SALE out of the channel that produced it,
        // so conversion-by-channel is wrong exactly where it matters most.
        if (in_array(self::normalizeSource($source), self::$metasearchSources, true)) {
            return 'metasearch';
        }

        $explicit = self::value($request, array('traffic_channel', 'trafficChannel', 'channel'));
        if ($explicit === null) {
            $explicit = self::value($request, array('utm_medium', 'utmMedium'));
        }
        if ($explicit === null) {
            $explicit = self::queryValue($server, array('traffic_channel', 'trafficChannel', 'channel', 'utm_medium', 'utmMedium'));
        }
        if ($explicit === null) {
            $explicit = self::value($server, array('HTTP_X_TRAFFIC_CHANNEL', 'HTTP_X_CHANNEL'));
        }

        $channel = self::normalizeChannel($explicit);
        return $channel !== 'unknown' ? $channel : self::channelForSource($source);
    }

    private static function channelForSource($source)
    {
        $source = self::normalizeSource($source);
        if (in_array($source, self::$metasearchSources, true)) {
            return 'metasearch';
        }
        if ($source === 'front' || $source === 'mobile_app') {
            return 'owned';
        }
        if ($source === 'backend') {
            return 'backoffice';
        }
        if ($source === 'google') {
            return 'paid';
        }
        if (in_array($source, self::$socialSources, true)) {
            return 'social';
        }
        if (in_array($source, self::$organicSources, true)) {
            return 'organic';
        }
        if ($source === 'referral') {
            return 'referral';
        }
        return 'unknown';
    }

    private static function sourceCandidates(array $request, array $server)
    {
        $candidates = array();
        $requestValue = self::value($request, array(
            'traffic_source',
            'trafficSource',
            'metasearcher',
            'metasearch',
            'utm_source',
            'utmSource',
            'partner',
            'partnerName',
            'source',
        ));
        if ($requestValue !== null) {
            $candidates[] = $requestValue;
        }

        $queryValue = self::queryValue($server, array(
            'traffic_source',
            'trafficSource',
            'utm_source',
            'utmSource',
            'source',
        ));
        if ($queryValue !== null) {
            $candidates[] = $queryValue;
        }

        $refererSource = self::sourceFromReferer(self::value($server, array('HTTP_REFERER', 'HTTP_REFERRER')));
        if ($refererSource !== null) {
            $candidates[] = $refererSource;
        }

        $header = self::value($server, array(
            'HTTP_X_TRAFFIC_SOURCE',
            'HTTP_X_UTM_SOURCE',
            'HTTP_X_SOURCE',
            'HTTP_X_PARTNER',
        ));
        if ($header !== null) {
            $candidates[] = $header;
        }

        return $candidates;
    }

    private static function sourceFromReferer($referer)
    {
        if ($referer === null || $referer === '') {
            return null;
        }
        $host = parse_url((string) $referer, PHP_URL_HOST);
        if ($host === null || $host === false || $host === '') {
            return null;
        }
        $host = self::normalizeToken($host);
        if (strpos($host, 'google') !== false && strpos($host, 'flight') !== false) {
            return 'google_flights';
        }
        if (strpos($host, 'google') !== false) {
            return 'google';
        }
        if (strpos($host, 'skyscanner') !== false) {
            return 'skyscanner';
        }
        if (strpos($host, 'kayak') !== false) {
            return 'kayak';
        }
        if (strpos($host, 'mundi') !== false) {
            return 'mundi';
        }
        if (strpos($host, 'viajala') !== false) {
            return 'viajala';
        }
        return null;
    }

    private static function queryValue(array $server, array $keys)
    {
        $query = null;
        if (isset($server['QUERY_STRING'])) {
            $query = $server['QUERY_STRING'];
        } elseif (isset($server['REQUEST_URI'])) {
            $query = parse_url((string) $server['REQUEST_URI'], PHP_URL_QUERY);
        }
        if ($query === null || $query === false || $query === '') {
            return null;
        }
        $params = array();
        parse_str(substr((string) $query, 0, self::MAX_QUERY_BYTES), $params);
        return self::value($params, $keys);
    }

    private static function value(array $data, array $keys)
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data) && self::scalar($data[$key]) !== null) {
                return self::scalar($data[$key]);
            }
        }
        return null;
    }

    private static function scalar($value)
    {
        if (is_scalar($value)) {
            return substr((string) $value, 0, self::MAX_VALUE_BYTES);
        }
        return null;
    }

    private static function normalizeToken($value)
    {
        $value = substr((string) $value, 0, self::MAX_VALUE_BYTES);
        $value = strtolower(trim($value));
        $value = str_replace(array('(', ')'), '_', $value);
        $value = preg_replace('/[^a-z0-9]+/', '_', $value);
        $value = $value === null ? '' : $value;
        $value = trim((string) $value, '_');
        $value = preg_replace('/_+/', '_', $value);
        return $value === null ? '' : $value;
    }
}
