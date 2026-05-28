<?php

namespace Elven\Observability\PhpLegacy\Metrics;

final class Histogram
{
    private $facade;
    private $name;
    private $unit;

    public function __construct(MetricFacade $facade, $name, $unit = '')
    {
        $this->facade = $facade;
        $this->name = (string) $name;
        $this->unit = (string) $unit;
    }

    public function record($value, array $attributes = array())
    {
        $this->facade->recordHistogram($this->name, (float) $value, $attributes, $this->unit);
    }
}
