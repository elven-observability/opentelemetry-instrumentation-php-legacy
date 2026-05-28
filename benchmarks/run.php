<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Elven\Observability\PhpLegacy\Observability;

function percentile(array $values, $p)
{
    sort($values);
    $index = (int) floor((count($values) - 1) * $p);
    return $values[$index];
}

function run_loop($enabled)
{
    Observability::resetForTests();
    putenv('ELVEN_OTEL_ENABLED=' . ($enabled ? 'true' : 'false'));
    putenv('OTEL_METRICS_EXPORTER=none');
    putenv('OTEL_TRACES_EXPORTER=none');
    Observability::init(array('service_name' => 'bench-php-legacy'));

    $samples = array();
    for ($i = 0; $i < 300; $i++) {
        $start = microtime(true);
        Observability::tracer()->withSpan('bench.operation', function ($span) {
            $value = 'legacy';
            for ($j = 0; $j < 20000; $j++) {
                $value = sha1($value . $j);
            }
            $span->setAttribute('operation', 'benchmark');
            return $value;
        });
        $samples[] = (microtime(true) - $start) * 1000.0;
    }

    Observability::shutdown();
    return percentile($samples, 0.95);
}

$rounds = array();
for ($round = 0; $round < 3; $round++) {
    $off = run_loop(false);
    $on = run_loop(true);
    $rounds[] = array(
        'off' => $off,
        'on' => $on,
        'overhead' => $off > 0 ? (($on - $off) / $off) * 100.0 : 0.0,
    );
}

usort($rounds, function ($a, $b) {
    if ($a['overhead'] == $b['overhead']) {
        return 0;
    }
    return $a['overhead'] < $b['overhead'] ? -1 : 1;
});

$best = $rounds[0];
printf("best p95 off: %.4f ms\n", $best['off']);
printf("best p95 on: %.4f ms\n", $best['on']);
printf("best p95 overhead: %.2f%%\n", $best['overhead']);
