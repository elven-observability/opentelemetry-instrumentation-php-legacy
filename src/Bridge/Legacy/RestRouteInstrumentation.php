<?php

namespace Elven\Observability\PhpLegacy\Bridge\Legacy;

use Elven\Observability\PhpLegacy\Instrumentation\HttpServerInstrumentation;
use Elven\Observability\PhpLegacy\Instrumentation\Slim2Instrumentation;

final class RestRouteInstrumentation
{
    private function __construct()
    {
    }

    public static function restRoute($version, $controller, $action)
    {
        return Slim2Instrumentation::restRoute($version, $controller, $action);
    }

    public static function traceRestAction($version, $controller, $action, callable $callback)
    {
        return HttpServerInstrumentation::instrument(
            self::restRoute($version, $controller, $action),
            $callback
        );
    }
}
