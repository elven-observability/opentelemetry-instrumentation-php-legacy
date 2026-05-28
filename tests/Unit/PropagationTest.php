<?php

namespace Elven\Observability\PhpLegacy\Tests\Unit;

use Elven\Observability\PhpLegacy\Propagation\BaggagePropagator;
use Elven\Observability\PhpLegacy\Propagation\TraceContextPropagator;
use Elven\Observability\PhpLegacy\Trace\SpanContext;
use PHPUnit\Framework\TestCase;

final class PropagationTest extends TestCase
{
    public function testTraceparentValidExtractAndInject(): void
    {
        $carrier = array(
            'HTTP_TRACEPARENT' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01',
            'HTTP_TRACESTATE' => 'vendor=value',
        );

        $context = (new TraceContextPropagator())->extract($carrier);

        self::assertTrue($context->isValid());
        self::assertTrue($context->isSampled());
        self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $context->traceId());

        $out = array();
        (new TraceContextPropagator())->inject($out, $context);
        self::assertSame($carrier['HTTP_TRACEPARENT'], $out['traceparent']);
        self::assertSame('vendor=value', $out['tracestate']);
    }

    public function testInvalidTraceparentIsRejected(): void
    {
        $context = (new TraceContextPropagator())->extract(array(
            'traceparent' => '00-00000000000000000000000000000000-00f067aa0ba902b7-01',
        ));

        self::assertFalse($context->isValid());
    }

    public function testBaggageParsingAndInjection(): void
    {
        $baggage = (new BaggagePropagator())->extract(array(
            'baggage' => 'tenant=acme, unsafe member, channel=api%20v2, token=secret, user_id=42',
        ));

        self::assertSame('acme', $baggage['tenant']);
        self::assertSame('api v2', $baggage['channel']);
        self::assertArrayNotHasKey('token', $baggage);
        self::assertArrayNotHasKey('user_id', $baggage);

        $out = array();
        (new BaggagePropagator())->inject($out, $baggage);
        self::assertStringContainsString('tenant=acme', $out['baggage']);
    }

    public function testSpanContextRejectsZeroSpan(): void
    {
        $context = new SpanContext('4bf92f3577b34da6a3ce929d0e0e4736', '0000000000000000');
        self::assertFalse($context->isValid());
    }
}
