<?php

namespace Elven\Observability\PhpLegacy\Instrumentation;

final class Slim2Instrumentation
{
    public static function restRoute($version, $controller, $action)
    {
        $version = strtolower((string) $version);
        if (strpos($version, 'v') !== 0) {
            $version = 'v' . $version;
        }
        return sprintf('/rest/%s/%s/%s', $version, strtolower((string) $controller), strtolower((string) $action));
    }

    public static function instrumentRestRoute($version, $controller, $action, callable $callback)
    {
        return HttpServerInstrumentation::instrument(self::restRoute($version, $controller, $action), $callback);
    }
}
