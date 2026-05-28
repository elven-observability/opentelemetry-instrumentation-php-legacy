<?php

namespace Elven\Observability\PhpLegacy\Trace\Sampler;

use Elven\Observability\PhpLegacy\Trace\SpanContext;

final class ParentBasedTraceIdRatioSampler implements SamplerInterface
{
    private $ratio;
    private $mode;

    public function __construct($ratio = 1.0, $mode = 'parentbased_traceidratio')
    {
        $this->ratio = max(0.0, min(1.0, (float) $ratio));
        $this->mode = strtolower((string) $mode);
    }

    public function shouldSample($traceId, SpanContext $parentContext, $spanName, array $attributes = array())
    {
        if ($this->mode === 'always_on') {
            return true;
        }
        if ($this->mode === 'always_off') {
            return false;
        }
        if ($parentContext->isValid()) {
            return $parentContext->isSampled();
        }
        if ($this->ratio >= 1.0) {
            return true;
        }
        if ($this->ratio <= 0.0) {
            return false;
        }

        $prefix = substr((string) $traceId, 0, 15);
        $value = hexdec($prefix);
        $max = hexdec('fffffffffffffff');
        return ($value / $max) < $this->ratio;
    }
}
