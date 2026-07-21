<?php

namespace Elven\Observability\PhpLegacy\Bridge\Legacy;

use Elven\Observability\PhpLegacy\Instrumentation\HttpServerInstrumentation;
use Elven\Observability\PhpLegacy\Privacy\UrlSanitizer;

/**
 * Safe route templates for custom front controllers with module maps.
 */
final class FrontControllerInstrumentation
{
    private function __construct()
    {
    }

    /**
     * @param string $uri
     * @param array  $routeDefinitions module => ['submodules' => [...]]
     * @return string
     */
    public static function routeFromUri($uri, array $routeDefinitions)
    {
        $path = parse_url((string) $uri, PHP_URL_PATH);
        if (!is_string($path)) {
            return '/{unmatched}';
        }
        $segments = array_values(array_filter(explode('/', trim($path, '/')), array(__CLASS__, 'notEmpty')));
        if (!$segments) {
            return '/';
        }

        $module = self::canonicalKey($segments[0], $routeDefinitions);
        if ($module === null) {
            return '/{unmatched}';
        }

        $template = '/' . self::safeLiteral($module);
        $definition = isset($routeDefinitions[$module]) && is_array($routeDefinitions[$module])
            ? $routeDefinitions[$module]
            : array();
        $submodules = isset($definition['submodules']) && is_array($definition['submodules'])
            ? $definition['submodules']
            : array();
        $offset = 1;

        if (isset($segments[1])) {
            $submodule = self::canonicalKey($segments[1], $submodules);
            if ($submodule !== null) {
                $template .= '/' . self::safeLiteral($submodule);
                $offset = 2;
            }
        }

        if (isset($segments[$offset])) {
            $template .= '/{id}';
        }
        if (isset($segments[$offset + 1])) {
            $template .= '/{action}';
        }
        if (count($segments) > $offset + 2) {
            $template .= '/{rest}';
        }

        return $template;
    }

    public static function beginFromGlobals(array $routeDefinitions, $statusResolver = null)
    {
        $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
        return HttpServerInstrumentation::begin(
            self::routeFromUri($uri, $routeDefinitions),
            $statusResolver
        );
    }

    private static function canonicalKey($candidate, array $values)
    {
        $candidate = strtolower((string) $candidate);
        foreach ($values as $key => $value) {
            if (strtolower((string) $key) === $candidate) {
                return (string) $key;
            }
        }
        return null;
    }

    private static function safeLiteral($value)
    {
        $value = trim(UrlSanitizer::sanitizePath((string) $value), '/');
        if ($value === '' || strpos($value, '{') !== false || strlen($value) > 80) {
            return '{route}';
        }
        return $value;
    }

    private static function notEmpty($value)
    {
        return $value !== '';
    }
}
