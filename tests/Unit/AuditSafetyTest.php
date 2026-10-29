<?php

namespace Elven\Observability\PhpLegacy\Tests\Unit;

use Elven\Observability\PhpLegacy\Config\EnvConfigResolver;
use Elven\Observability\PhpLegacy\Export\CircuitBreaker;
use Elven\Observability\PhpLegacy\Export\HttpJsonClient;
use Elven\Observability\PhpLegacy\Metrics\MetricFacade;
use Elven\Observability\PhpLegacy\Privacy\AttributeRedactor;
use Elven\Observability\PhpLegacy\Support\TelemetryValueLimiter;
use PHPUnit\Framework\TestCase;

final class AuditSafetyTest extends TestCase
{
    public function testContendedCircuitLockSkipsTelemetry(): void
    {
        $breaker = new CircuitBreaker(3, 30000, 'audit-lock-' . bin2hex(random_bytes(8)));
        $property = new \ReflectionProperty($breaker, 'stateFile');
        if (PHP_VERSION_ID < 80100) {
            $property->setAccessible(true);
        }
        $path = $property->getValue($breaker);
        self::assertIsString($path);
        $code = '$h=fopen($argv[1], "c+"); flock($h, LOCK_EX); echo "ready\n"; fflush(STDOUT); usleep(300000);';
        $process = proc_open(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code)
            . ' ' . escapeshellarg($path), array(1 => array('pipe', 'w')), $pipes);
        self::assertIsResource($process);
        try {
            self::assertSame("ready\n", fgets($pipes[1]));
            self::assertFalse($breaker->allowRequest());
        } finally {
            fclose($pipes[1]);
            proc_close($process);
            @unlink($path);
        }
    }

    public function testDynamicInstrumentsHaveBoundedCaches(): void
    {
        $redactor = new AttributeRedactor(EnvConfigResolver::resolve(array('enabled' => false)));
        $metrics = new MetricFacade(null, $redactor, array(), false);
        for ($i = 0; $i < 10000; $i++) {
            $metrics->counter('dynamic.' . $i)->add(1);
            $metrics->histogram('dynamic.' . $i)->record(1);
            $metrics->gauge('dynamic.' . $i)->set(1);
        }
        foreach (array('counters', 'histograms', 'gauges') as $name) {
            $property = new \ReflectionProperty($metrics, $name);
            if (PHP_VERSION_ID < 80100) {
                $property->setAccessible(true);
            }
            self::assertLessThanOrEqual(256, count($property->getValue($metrics)));
        }
    }

    public function testArrayPrivacyAndRedactionCacheAreBounded(): void
    {
        $redactor = new AttributeRedactor(EnvConfigResolver::resolve(array()));
        $value = TelemetryValueLimiter::limit(array('password' => 'canary-secret', 'safe' => 'person@example.com'), 4096);
        $safe = $redactor->redactValue('safe', $value);
        self::assertSame('[REDACTED]', $safe['password']);
        self::assertStringNotContainsString('person@example.com', $safe['safe']);
        for ($i = 0; $i < 10000; $i++) {
            $redactor->redactValue('dynamic.' . $i, 'value');
        }
        $property = new \ReflectionProperty($redactor, 'keyPlanCache');
        if (PHP_VERSION_ID < 80100) {
            $property->setAccessible(true);
        }
        self::assertLessThanOrEqual(512, count($property->getValue($redactor)));
    }

    public function testStandardSdkDisableOverridesExplicitEnable(): void
    {
        putenv('OTEL_SDK_DISABLED=true');
        try {
            self::assertFalse(EnvConfigResolver::resolve(array('enabled' => true))->isEnabled());
        } finally {
            putenv('OTEL_SDK_DISABLED');
        }
    }

    public function testStreamFailureDoesNotRecursivelyExportFromTheApplicationErrorHandler(): void
    {
        if (function_exists('curl_init')) {
            self::markTestSkipped('Run with -d disable_functions=curl_init to exercise stream fallback.');
        }
        $client = new HttpJsonClient(array(), 10, new CircuitBreaker(3, 30000));
        $calls = 0;
        set_error_handler(function () use ($client, &$calls) {
            $calls++;
            if ($calls > 5) {
                throw new \RuntimeException('bounded recursion reproduction');
            }
            $client->post('http://127.0.0.1:1/v1/logs', array());
            return true;
        });
        try {
            self::assertFalse($client->post('http://127.0.0.1:1/v1/logs', array()));
        } finally {
            restore_error_handler();
        }
        self::assertLessThanOrEqual(1, $calls);
    }
}
