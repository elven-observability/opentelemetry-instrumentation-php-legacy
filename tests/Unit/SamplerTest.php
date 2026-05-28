<?php

namespace Elven\Observability\PhpLegacy\Tests\Unit;

use Elven\Observability\PhpLegacy\Trace\Sampler\ParentBasedTraceIdRatioSampler;
use Elven\Observability\PhpLegacy\Trace\SpanContext;
use PHPUnit\Framework\TestCase;

final class SamplerTest extends TestCase
{
    public function testParentDecisionWins(): void
    {
        $sampledParent = new SpanContext('4bf92f3577b34da6a3ce929d0e0e4736', '00f067aa0ba902b7', '01');
        $unsampledParent = new SpanContext('4bf92f3577b34da6a3ce929d0e0e4736', '00f067aa0ba902b7', '00');
        $sampler = new ParentBasedTraceIdRatioSampler(0.0);

        self::assertTrue($sampler->shouldSample($sampledParent->traceId(), $sampledParent, 'child'));
        self::assertFalse($sampler->shouldSample($unsampledParent->traceId(), $unsampledParent, 'child'));
    }

    public function testAlwaysOnAndOff(): void
    {
        self::assertTrue((new ParentBasedTraceIdRatioSampler(0.0, 'always_on'))->shouldSample(str_repeat('a', 32), SpanContext::invalid(), 'root'));
        self::assertFalse((new ParentBasedTraceIdRatioSampler(1.0, 'always_off'))->shouldSample(str_repeat('a', 32), SpanContext::invalid(), 'root'));
    }
}
