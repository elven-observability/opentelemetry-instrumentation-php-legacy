<?php

namespace Elven\Observability\PhpLegacy\Tests\Unit;

use Elven\Observability\PhpLegacy\Context\RequestContext;
use Elven\Observability\PhpLegacy\Instrumentation\HeaderInjector;
use Elven\Observability\PhpLegacy\Instrumentation\HttpServerInstrumentation;
use Elven\Observability\PhpLegacy\Observability;
use Elven\Observability\PhpLegacy\Tests\Support\Env;
use PHPUnit\Framework\TestCase;

final class BaggageContextTest extends TestCase
{
    protected function setUp(): void
    {
        Env::reset();
        putenv('OTEL_TRACES_EXPORTER=none');
        putenv('OTEL_LOGS_EXPORTER=none');
        putenv('ELVEN_OTEL_ENABLED=true');
        Observability::init(array('service_name' => 'baggage-test'));
        $_GET = array();
        $_POST = array();
        RequestContext::reset();
    }

    protected function tearDown(): void
    {
        $_GET = array();
        $_POST = array();
        foreach (array('REQUEST_METHOD', 'REQUEST_URI', 'HTTP_USER_AGENT', 'HTTP_BAGGAGE') as $k) {
            unset($_SERVER[$k]);
        }
        RequestContext::reset();
    }

    public function testRequestContextStoreSetGetResetAndBoolCoercion(): void
    {
        RequestContext::set('is_bot', true);
        RequestContext::set('traffic_source', 'google');
        self::assertSame('true', RequestContext::get('is_bot'));
        self::assertSame('google', RequestContext::get('traffic_source'));
        self::assertSame(array('is_bot' => 'true', 'traffic_source' => 'google'), RequestContext::all());

        RequestContext::reset();
        self::assertSame(array(), RequestContext::all());
        self::assertNull(RequestContext::get('is_bot'));
    }

    public function testServerSpanSeedsContextAndExtractsInboundBaggage(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/rest/v2/aerial/search';
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; Googlebot/2.1)';
        $_SERVER['HTTP_BAGGAGE'] = 'upstream_key=upstream_val';
        $_GET = array('utm_source' => 'google', 'utm_medium' => 'cpc');

        HttpServerInstrumentation::startFromGlobals('/rest/v2/aerial/search');

        $ctx = RequestContext::all();
        self::assertSame('google', $ctx['traffic_source']);
        self::assertSame('true', $ctx['is_bot']);
        self::assertSame('upstream_val', $ctx['upstream_key'], 'inbound baggage must be received');
    }

    public function testOutboundInjectAutoCarriesCurrentContextBaggage(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/rest/v2/aerial/search';
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; Googlebot/2.1)';
        $_GET = array('utm_source' => 'google', 'utm_medium' => 'cpc');
        HttpServerInstrumentation::startFromGlobals('/rest/v2/aerial/search');

        // No explicit baggage passed -> must default to the request context.
        $headers = HeaderInjector::inject(array());

        self::assertArrayHasKey('baggage', $headers);
        self::assertStringContainsString('traffic_source=google', $headers['baggage']);
        self::assertStringContainsString('is_bot=true', $headers['baggage']);
    }

    public function testContextDoesNotLeakBetweenRequests(): void
    {
        // Request 1: bot from google with inbound baggage.
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/rest/v2/aerial/search';
        $_SERVER['HTTP_USER_AGENT'] = 'Googlebot/2.1';
        $_SERVER['HTTP_BAGGAGE'] = 'upstream_key=upstream_val';
        $_GET = array('utm_source' => 'google', 'utm_medium' => 'cpc');
        HttpServerInstrumentation::startFromGlobals('/rest/v2/aerial/search');
        self::assertArrayHasKey('upstream_key', RequestContext::all());

        // Request 2 in the SAME worker: human, direct, no inbound baggage.
        $_GET = array();
        $_SERVER['REQUEST_URI'] = '/rest/v2/session/login';
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0) Chrome/124';
        unset($_SERVER['HTTP_BAGGAGE']);
        HttpServerInstrumentation::startFromGlobals('/rest/v2/session/login');

        $ctx2 = RequestContext::all();
        self::assertArrayNotHasKey('upstream_key', $ctx2, 'request 1 baggage must not leak into request 2');
        self::assertSame('false', $ctx2['is_bot']);
    }

    /**
     * bot.category must reach the next process. Before this, only is_bot rode the
     * baggage: the reservation worker (CONSUMER span, same trace) could say THAT
     * a purchase came from a bot but never WHICH kind -- a health probe, a search
     * crawler and a script all looked the same there.
     */
    public function testServerSpanPropagatesBotCategoryInTheBaggage(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/rest/v2/aerial/search';
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; Googlebot/2.1)';
        HttpServerInstrumentation::startFromGlobals('/rest/v2/aerial/search');

        self::assertSame('search_engine', RequestContext::get('bot.category'));
        $headers = HeaderInjector::inject(array());
        self::assertStringContainsString('is_bot=true', $headers['baggage']);
        self::assertStringContainsString('bot.category=search_engine', $headers['baggage']);

        // Human: the category is explicit, not absent, so downstream can tell
        // "classified human" from "never classified".
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0) Chrome/124';
        HttpServerInstrumentation::startFromGlobals('/rest/v2/session/login');
        self::assertSame('none', RequestContext::get('bot.category'));
    }

    /**
     * Inbound baggage is client-controlled. The server's own classification must
     * overwrite a forged is_bot / bot.category, exactly as it already did for
     * traffic_source.
     */
    public function testForgedInboundBotBaggageIsOverwrittenByTheServerClassification(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/rest/v2/aerial/search';
        $_SERVER['HTTP_USER_AGENT'] = 'kube-probe/1.28';
        $_SERVER['HTTP_BAGGAGE'] = 'is_bot=false,bot.category=human_forjado';
        HttpServerInstrumentation::startFromGlobals('/rest/v2/aerial/search');

        self::assertSame('true', RequestContext::get('is_bot'));
        self::assertSame('monitoring', RequestContext::get('bot.category'));
    }
}
