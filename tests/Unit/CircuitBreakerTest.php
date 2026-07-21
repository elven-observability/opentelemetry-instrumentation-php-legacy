<?php

namespace Elven\Observability\PhpLegacy\Tests\Unit;

use Elven\Observability\PhpLegacy\Export\CircuitBreaker;
use Elven\Observability\PhpLegacy\Export\HttpJsonClient;
use PHPUnit\Framework\TestCase;

final class CircuitBreakerTest extends TestCase
{
    public function testSharedStateSurvivesAcrossInstances(): void
    {
        $key = $this->uniqueKey('instances');
        $first = new CircuitBreaker(2, 30000, $key);
        $first->recordFailure();
        $first->recordFailure();

        $second = new CircuitBreaker(2, 30000, $key);
        self::assertFalse($second->allowRequest());

        $second->recordSuccess();
        self::assertTrue((new CircuitBreaker(2, 30000, $key))->allowRequest());
    }

    public function testOnlyOneHalfOpenProbeIsAllowed(): void
    {
        $key = $this->uniqueKey('half-open');
        $breaker = new CircuitBreaker(1, 50, $key);
        $breaker->recordFailure();
        usleep(70000);

        $probe = new CircuitBreaker(1, 50, $key);
        self::assertTrue($probe->allowRequest());
        self::assertFalse((new CircuitBreaker(1, 50, $key))->allowRequest());

        $probe->recordSuccess();
        self::assertTrue((new CircuitBreaker(1, 50, $key))->allowRequest());
    }

    public function testNetworkFailuresAreSharedAcrossSignalPaths(): void
    {
        $port = random_int(30000, 45000);
        $origin = 'http://127.0.0.1:' . $port;
        $paths = array('/v1/traces', '/v1/metrics', '/v1/logs');

        foreach ($paths as $path) {
            $client = new HttpJsonClient(array(), 20);
            self::assertFalse($client->post($origin . $path, array('resourceSpans' => array())));
        }

        $originBreaker = new CircuitBreaker(3, 30000, 'origin:' . $origin);
        self::assertFalse($originBreaker->allowRequest());

        $originBreaker->recordSuccess();
        foreach ($paths as $path) {
            (new CircuitBreaker(3, 30000, 'endpoint:' . $origin . $path))->recordSuccess();
        }
    }

    private function uniqueKey($suffix)
    {
        return 'phpunit:' . $suffix . ':' . bin2hex(random_bytes(12));
    }
}
