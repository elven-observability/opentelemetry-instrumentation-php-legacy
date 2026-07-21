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

    public function testInvalidOrOversizedTracestateIsDroppedWithoutDroppingTraceparent(): void
    {
        $propagator = new TraceContextPropagator();
        $base = array(
            'traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01',
        );

        $context = $propagator->extract($base + array('tracestate' => "vendor=value\r\nx-injected=1"));
        self::assertTrue($context->isValid());
        self::assertSame('', $context->traceState());

        $context = $propagator->extract($base + array('tracestate' => str_repeat('a', 513)));
        self::assertTrue($context->isValid());
        self::assertSame('', $context->traceState());
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

    public function testBaggageRejectsOversizedHeadersAndRawIdentifiersButAllowsHashes(): void
    {
        $propagator = new BaggagePropagator();
        self::assertSame(array(), $propagator->extract(array(
            'baggage' => str_repeat('x', BaggagePropagator::MAX_HEADER_BYTES + 1),
        )));

        $hash = str_repeat('a', 32);
        $baggage = $propagator->extract(array(
            'baggage' => 'tenant.id=raw-tenant,organization_id=raw-org,tenant_hash=' . $hash . ',tenant.id=' . $hash,
        ));
        self::assertArrayNotHasKey('organization_id', $baggage);
        self::assertSame($hash, $baggage['tenant.id']);
        self::assertSame($hash, $baggage['tenant_hash']);
    }

    public function testBaggagePreservesLiteralPlusCharacters(): void
    {
        $baggage = (new BaggagePropagator())->extract(array(
            'baggage' => 'channel=api+partner,encoded=api%2Bencoded',
        ));

        self::assertSame('api+partner', $baggage['channel']);
        self::assertSame('api+encoded', $baggage['encoded']);
    }

    public function testSpanContextRejectsZeroSpan(): void
    {
        $context = new SpanContext('4bf92f3577b34da6a3ce929d0e0e4736', '0000000000000000');
        self::assertFalse($context->isValid());
    }
}
