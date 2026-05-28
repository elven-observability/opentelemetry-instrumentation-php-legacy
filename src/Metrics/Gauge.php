<?php

namespace Elven\Observability\PhpLegacy\Metrics;

final class Gauge
{
    private $facade;
    private $name;

    public function __construct(MetricFacade $facade, $name)
    {
        $this->facade = $facade;
        $this->name = (string) $name;
    }

    public function set($value, array $attributes = array())
    {
        $this->facade->recordGauge($this->name, (float) $value, $attributes);
    }
}
