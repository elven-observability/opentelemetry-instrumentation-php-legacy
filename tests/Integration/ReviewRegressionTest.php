<?php

namespace Elven\Observability\PhpLegacy\Tests\Integration;

use Elven\Observability\PhpLegacy\Observability;
use PHPUnit\Framework\TestCase;

final class ReviewRegressionTest extends TestCase
{
    public function testReplacingAttributesAndStatusCountsRetainedBytes(): void
    {
        Observability::resetForTests();
        $handle = Observability::init(array('enabled' => true, 'endpoint' => 'http://127.0.0.1:1', 'timeout_millis' => 10));
        $span = Observability::tracer()->startSpan('progress');
        for ($i = 0; $i < 100; $i++) {
            $span->setAttribute('progress', 'tick.' . $i . ':' . str_repeat('x', 1900));
            $span->setStatus('ERROR', 'tick.' . $i . ':' . str_repeat('x', 1900));
        }
        self::assertStringStartsWith('tick.99:', $span->attributes()['progress']);
        self::assertStringStartsWith('tick.99:', $span->statusMessage());
        self::assertLessThan(6000, $span->estimatedBytes());
        $span->end();
        $handle->shutdown();
        Observability::resetForTests();
    }
}
