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

    /**
     * @param string        $version
     * @param string        $controller
     * @param string        $action
     * @param callable      $callback       Receives the span and runs the route handler.
     * @param callable|null $statusResolver Optional resolver for the real HTTP status code,
     *                                       forwarded to HttpServerInstrumentation::instrument().
     *                                       Use it on Slim 2 / deferred-finalize frameworks, e.g.
     *                                       function () use ($app) { return $app->response->getStatus(); }.
     */
    public static function traceRestAction($version, $controller, $action, callable $callback, $statusResolver = null)
    {
        return HttpServerInstrumentation::instrument(
            self::restRoute($version, $controller, $action),
            $callback,
            $statusResolver
        );
    }
}
