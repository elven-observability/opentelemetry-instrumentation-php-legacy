<?php

namespace Elven\Observability\PhpLegacy\Export;

final class CircuitBreaker
{
    private $failureThreshold;
    private $resetTimeoutMillis;
    private $failures;
    private $openedAtMillis;

    public function __construct($failureThreshold = 3, $resetTimeoutMillis = 30000)
    {
        $this->failureThreshold = (int) $failureThreshold;
        $this->resetTimeoutMillis = (int) $resetTimeoutMillis;
        $this->failures = 0;
        $this->openedAtMillis = 0;
    }

    public function allowRequest()
    {
        if ($this->openedAtMillis === 0) {
            return true;
        }
        if ($this->nowMillis() - $this->openedAtMillis >= $this->resetTimeoutMillis) {
            return true;
        }
        return false;
    }

    public function recordSuccess()
    {
        $this->failures = 0;
        $this->openedAtMillis = 0;
    }

    public function recordFailure()
    {
        $this->failures++;
        if ($this->failures >= $this->failureThreshold) {
            $this->openedAtMillis = $this->nowMillis();
        }
    }

    private function nowMillis()
    {
        return (int) floor(microtime(true) * 1000);
    }
}
