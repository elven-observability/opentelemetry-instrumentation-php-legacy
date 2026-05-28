<?php

namespace Elven\Observability\PhpLegacy\Metrics;

final class Counter
{
    private $facade;
    private $name;

    public function __construct(MetricFacade $facade, $name)
    {
        $this->facade = $facade;
        $this->name = (string) $name;
    }

    public function add($value, array $attributes = array())
    {
        $this->facade->recordCounter($this->name, (float) $value, $attributes);
    }
}
