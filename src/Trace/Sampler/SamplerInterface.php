<?php

namespace Elven\Observability\PhpLegacy\Trace\Sampler;

use Elven\Observability\PhpLegacy\Trace\SpanContext;

interface SamplerInterface
{
    public function shouldSample($traceId, SpanContext $parentContext, $spanName, array $attributes = array());
}
