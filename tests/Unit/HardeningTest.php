<?php

namespace Elven\Observability\PhpLegacy\Tests\Unit;

use Elven\Observability\PhpLegacy\Context\RequestContext;
use Elven\Observability\PhpLegacy\Instrumentation\CacheInstrumentation;
use Elven\Observability\PhpLegacy\Instrumentation\HttpServerInstrumentation;
use Elven\Observability\PhpLegacy\Observability;
use Elven\Observability\PhpLegacy\Tests\Support\Env;
use PHPUnit\Framework\TestCase;

/**
 * Adversarial / "impossible scenario" tests: telemetry must never break, slow,
 * or alter the application's behavior, and must be zero-cost when OTel is off.
 */
final class HardeningTest extends TestCase
{
    protected function tearDown(): void
    {
        RequestContext::reset();
    }

    public function testCacheRecordIsNoOpWhenOtelDisabled(): void
    {
        Env::reset();
        putenv('OTEL_TRACES_EXPORTER=none');
        putenv('OTEL_LOGS_EXPORTER=none');
        // The app's OtelBootstrap forces enabled=false unless ELVEN_OTEL_ENABLED
        // is set; emulate the OTel-off production default explicitly.
        putenv('ELVEN_OTEL_ENABLED=false');
        Observability::init(array('service_name' => 'cache-disabled'));

        CacheInstrumentation::record('redis', 'hit', 1.0);
        CacheInstrumentation::record('redis', 'miss', 1.0);

        $names = array();
        foreach (Observability::metrics()->collect() as $metric) {
            $names[] = $metric['name'];
        }
        self::assertNotContains('elven.php.cache.operations', $names, 'cache metrics must be zero-cost when OTel is off');
    }

    public function testObserveRecordsErrorAndRethrowsWhenReaderThrows(): void
    {
        Env::reset();
        putenv('OTEL_TRACES_EXPORTER=none');
        putenv('OTEL_LOGS_EXPORTER=none');
        putenv('ELVEN_OTEL_ENABLED=true');
        Observability::init(array('service_name' => 'cache-observe'));

        $thrown = null;
        try {
            CacheInstrumentation::observe('redis', function () {
                throw new \RuntimeException('driver down');
            });
            self::fail('observe must rethrow the reader exception');
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }
        self::assertSame('driver down', $thrown->getMessage());

        $error = 0;
        foreach (Observability::metrics()->collect() as $metric) {
            if ($metric['name'] !== 'elven.php.cache.operations') {
                continue;
            }
            foreach ($metric['points'] as $point) {
                if (($point['attributes']['result'] ?? null) === 'error') {
                    $error += $point['value'];
                }
            }
        }
        self::assertSame(1.0, $error, 'a throwing cache reader must be recorded as an error outcome');
    }

    public function testCacheRecordNeverThrowsOnGarbageInput(): void
    {
        Env::reset();
        putenv('ELVEN_OTEL_ENABLED=true');
        Observability::init(array('service_name' => 'cache-garbage'));

        CacheInstrumentation::record('', 'totally-bogus', 'not-a-number');
        CacheInstrumentation::record(str_repeat('x', 9999), 'hit', -5);
        $this->addToAssertionCount(1);
    }

    public function testRequestContextIsBounded(): void
    {
        RequestContext::reset();
        for ($i = 0; $i < 200; $i++) {
            RequestContext::set('k' . $i, $i);
        }
        self::assertLessThanOrEqual(RequestContext::MAX_ITEMS, count(RequestContext::all()));
        // existing keys still updatable past the cap
        RequestContext::set('k0', 'updated');
        self::assertSame('updated', RequestContext::get('k0'));
    }

    public function testInstrumentRethrowsTheOriginalExceptionUnmasked(): void
    {
        Env::reset();
        putenv('OTEL_TRACES_EXPORTER=none');
        putenv('OTEL_LOGS_EXPORTER=none');
        putenv('ELVEN_OTEL_ENABLED=true');
        Observability::init(array('service_name' => 'instrument-rethrow'));

        $original = new \LogicException('handler failure');
        $caught = null;
        try {
            HttpServerInstrumentation::instrument('GET /x', function () use ($original) {
                throw $original;
            });
        } catch (\Throwable $e) {
            $caught = $e;
        }
        self::assertSame($original, $caught, 'the caller must receive the exact handler exception, never a telemetry one');
    }

    public function testInstrumentReturnsHandlerValueUnchanged(): void
    {
        Env::reset();
        putenv('OTEL_TRACES_EXPORTER=none');
        putenv('OTEL_LOGS_EXPORTER=none');
        putenv('ELVEN_OTEL_ENABLED=true');
        Observability::init(array('service_name' => 'instrument-return'));

        $result = HttpServerInstrumentation::instrument('GET /x', function () {
            return array('ok' => true, 'n' => 42);
        }, function () {
            return 200;
        });
        self::assertSame(array('ok' => true, 'n' => 42), $result);
    }

    public function testFinishNeverThrowsOnOddSpanOrStatus(): void
    {
        Env::reset();
        putenv('ELVEN_OTEL_ENABLED=true');
        Observability::init(array('service_name' => 'finish-odd'));

        // Non-span input is ignored; a real noop span with odd status must not throw.
        HttpServerInstrumentation::finish(null, 500);
        HttpServerInstrumentation::finish(new \Elven\Observability\PhpLegacy\Trace\NoopSpan(), 'not-numeric');
        HttpServerInstrumentation::finish(new \Elven\Observability\PhpLegacy\Trace\NoopSpan(), 418, new \RuntimeException('x'));
        $this->addToAssertionCount(1);
    }
}
