<?php

namespace Elven\Observability\PhpLegacy\Tests\Unit;

use Elven\Observability\PhpLegacy\Bridge\Legacy\FrontControllerInstrumentation;
use Elven\Observability\PhpLegacy\Observability;
use Elven\Observability\PhpLegacy\Support\ShutdownRegistry;
use Elven\Observability\PhpLegacy\Tests\Support\Env;
use PHPUnit\Framework\TestCase;

final class FrontControllerInstrumentationTest extends TestCase
{
    private $routes = array(
        'Customer' => array(
            'controller' => 'CustomerController',
            'submodules' => array(
                'app' => 'CustomerAppController',
                'blacklist' => 'CustomerBlacklistController',
            ),
        ),
        'Transaction' => array('controller' => 'TransactionController'),
        'Healthz' => array(
            'controller' => 'HealthController',
            'submodules' => array('live' => 'LiveController', 'ready' => 'ReadyController'),
        ),
    );

    protected function setUp(): void
    {
        Env::reset();
        putenv('ELVEN_OTEL_ENABLED=true');
        putenv('OTEL_TRACES_EXPORTER=none');
        putenv('OTEL_LOGS_EXPORTER=none');
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/Customer/app/12345/details?token=secret';
        $_SERVER['SERVER_NAME'] = 'api.test';
        $_SERVER['SERVER_PORT'] = '443';
        Observability::init(array('service_name' => 'front-controller-test'));
    }

    public function testBuildsBoundedRouteFromKnownModuleMap(): void
    {
        self::assertSame(
            '/Customer/app/{id}/{action}',
            FrontControllerInstrumentation::routeFromUri(
                '/customer/APP/12345/details?token=secret',
                $this->routes
            )
        );
        self::assertSame(
            '/Transaction/{id}/{action}/{rest}',
            FrontControllerInstrumentation::routeFromUri(
                '/Transaction/a@customer.test/history/private/more',
                $this->routes
            )
        );
    }

    public function testUnknownAndEmptyRoutesNeverPromoteAttackerInput(): void
    {
        self::assertSame(
            '/{unmatched}',
            FrontControllerInstrumentation::routeFromUri('/arbitrary-attacker-route/123', $this->routes)
        );
        self::assertSame('/', FrontControllerInstrumentation::routeFromUri('/', $this->routes));
    }

    public function testShutdownRegistryFinishesScopeBeforeExporterFlush(): void
    {
        $scope = FrontControllerInstrumentation::beginFromGlobals($this->routes);
        $span = $scope->span();
        self::assertFalse($span->isEnded());

        http_response_code(202);
        ShutdownRegistry::run();

        self::assertTrue($scope->isFinished());
        self::assertTrue($span->isEnded());
        self::assertSame(202, $span->attributes()['http.response.status_code']);

        $durationFound = false;
        foreach (Observability::metrics()->collect() as $metric) {
            if ($metric['name'] !== 'http.server.request.duration') {
                continue;
            }
            $durationFound = true;
            self::assertSame('/Customer/app/{id}/{action}', $metric['points'][0]['attributes']['route']);
        }
        self::assertTrue($durationFound);
    }

    public function testExplicitHandleShutdownAlsoFinalizesOpenRequestScope(): void
    {
        $scope = FrontControllerInstrumentation::beginFromGlobals($this->routes);
        self::assertFalse($scope->span()->isEnded());

        $shutdownResult = Observability::init()->shutdown();

        self::assertIsBool($shutdownResult);
        self::assertTrue($scope->isFinished());
        self::assertTrue($scope->span()->isEnded());
    }

    public function testTenantIdentifierIsHashedForSpanAndBaggage(): void
    {
        putenv('ELVEN_OTEL_ID_HASH_SALT=test-only-salt');
        $scope = FrontControllerInstrumentation::beginFromGlobals($this->routes);
        $scope->setHashedAttribute('tenant.id', 'raw-api-token');
        Observability::context()->setHashed('tenant.id', 'raw-api-token');

        $hashed = $scope->span()->attributes()['tenant.id'];
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $hashed);
        self::assertNotSame('raw-api-token', $hashed);
        self::assertSame($hashed, Observability::context()->get('tenant.id'));
        $scope->finish(null, 200);
    }
}
