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
    );

    private static $knownChannels = array(
        'owned',
        'metasearch',
        'paid',
        'organic',
        'partner',
        'backoffice',
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
        if (self::value($request, array(
            'skyScannerCode',
            'skyscannerCode',
            'sky_scanner_code',
            'SkyScannerCode',
        )) !== null || self::queryValue($server, array(
            'skyScannerCode',
            'skyscannerCode',
            'sky_scanner_code',
            'SkyScannerCode',
        )) !== null) {
            return 'skyscanner';
        }
        if (self::value($request, array('gclid', 'gbraid', 'wbraid')) !== null
            || self::queryValue($server, array('gclid', 'gbraid', 'wbraid')) !== null
        ) {
            return 'google';
        }
        return $fallback ?: 'unknown';
    }

    private static function detectChannel(array $request, array $server, $source)
    {
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
        if ($source === 'other') {
            return 'unknown';
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
