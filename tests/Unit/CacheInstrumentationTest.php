<?php

namespace Elven\Observability\PhpLegacy\Tests\Unit;

use Elven\Observability\PhpLegacy\Instrumentation\CacheInstrumentation;
use Elven\Observability\PhpLegacy\Observability;
use Elven\Observability\PhpLegacy\Tests\Support\Env;
use PHPUnit\Framework\TestCase;

final class CacheInstrumentationTest extends TestCase
{
    protected function setUp(): void
    {
        Env::reset();
        putenv('OTEL_TRACES_EXPORTER=none');
        putenv('OTEL_LOGS_EXPORTER=none');
        Observability::init(array('service_name' => 'cache-test'));
    }

    /** @return array<string,array<string,float>> name => {label combo => value} */
    private function operationPointsByResult(): array
    {
        $byResult = array();
        foreach (Observability::metrics()->collect() as $metric) {
            if ($metric['name'] !== 'elven.php.cache.operations') {
                continue;
            }
            foreach ($metric['points'] as $point) {
                $result = isset($point['attributes']['result']) ? $point['attributes']['result'] : '?';
                $byResult[$result] = (isset($byResult[$result]) ? $byResult[$result] : 0) + $point['value'];
            }
        }
        return $byResult;
    }

    public function testRecordEmitsCounterAndDurationWithBoundedLabels(): void
    {
        CacheInstrumentation::record('airports', 'hit', 1.5);
        CacheInstrumentation::record('airports', 'miss', 2.0);

        $names = array_map(function ($m) { return $m['name']; }, Observability::metrics()->collect());
        // collect() drains, so re-emit for the name assertion set
        CacheInstrumentation::record('airports', 'hit', 1.0);

        self::assertContains('elven.php.cache.operations', $names);
        self::assertContains('elven.php.cache.operation.duration', $names);
    }

    public function testResultIsClassifiedHitMissErrorByConvention(): void
    {
        self::assertSame('hit', CacheInstrumentation::classifyDefault(array('x' => 1)));
        self::assertSame('hit', CacheInstrumentation::classifyDefault('value'));
        self::assertSame('miss', CacheInstrumentation::classifyDefault(false));
        self::assertSame('error', CacheInstrumentation::classifyDefault(null));
    }

    public function testObserveReturnsValueUntouchedAndRecordsOutcome(): void
    {
        $value = CacheInstrumentation::observe('redis', function () {
            return array('cached' => true);
        });
        self::assertSame(array('cached' => true), $value);

        $miss = CacheInstrumentation::observe('redis', function () {
            return false;
        });
        self::assertFalse($miss);

        $driverError = CacheInstrumentation::observe('redis', function () {
            return null;
        });
        self::assertNull($driverError);

        $points = $this->operationPointsByResult();
        self::assertSame(1.0, $points['hit']);
        self::assertSame(1.0, $points['miss']);
        self::assertSame(1.0, $points['error']);
    }

    public function testInvalidResultAndNameAreNormalizedNotRejected(): void
    {
        CacheInstrumentation::record('Weird Name!!', 'bogus', null);
        $points = $this->operationPointsByResult();
        // unknown result normalizes to miss; the call still records (never throws)
        self::assertArrayHasKey('miss', $points);
    }

    public function testRecordNeverThrows(): void
    {
        // Even with odd inputs the cache path must stay alive.
        CacheInstrumentation::record('', 'hit', 'not-a-number');
        $this->addToAssertionCount(1);
    }
}
