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
        $this->unit = substr((string) $unit, 0, 63);
    }

    public function record($value, array $attributes = array())
    {
        $this->facade->recordHistogram($this->name, (float) $value, $attributes, $this->unit);
    }
}
