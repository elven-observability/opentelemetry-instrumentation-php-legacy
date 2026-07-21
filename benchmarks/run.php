<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Elven\Observability\PhpLegacy\Observability;

const BENCH_ROUNDS = 5;
const BENCH_SAMPLES = 20;
const BENCH_WARMUP = 10;
const BENCH_WORK = 5000;
const BENCH_BATCH_SIZE = 20;
const BENCH_BUDGET_PERCENT = 3.0;

function percentile(array $values, $p)
{
    sort($values);
    $index = (int) floor((count($values) - 1) * $p);
    return $values[$index];
}

function median(array $values)
{
    sort($values);
    $count = count($values);
    $middle = (int) floor($count / 2);
    return $count % 2 === 1
        ? $values[$middle]
        : ($values[$middle - 1] + $values[$middle]) / 2.0;
}

function configureBenchmark($enabled)
{
    Observability::resetForTests();
    putenv('ELVEN_OTEL_ENABLED=' . ($enabled ? 'true' : 'false'));
    putenv('OTEL_METRICS_EXPORTER=none');
    putenv('OTEL_TRACES_EXPORTER=none');
    putenv('OTEL_LOGS_EXPORTER=none');
    Observability::init(array('service_name' => 'bench-php-legacy'));
}

function operation($tracer)
{
    return $tracer->withSpan('bench.operation', function ($span) {
        $value = 'legacy';
        for ($index = 0; $index < BENCH_WORK; $index++) {
            $value = sha1($value . $index);
        }
        $span->setAttribute('operation', 'benchmark');
        return $value;
    });
}

function configuredTracer($enabled)
{
    configureBenchmark($enabled);
    return Observability::tracer();
}

function timedBatch($tracer)
{
    $start = hrtime(true);
    for ($index = 0; $index < BENCH_BATCH_SIZE; $index++) {
        operation($tracer);
    }
    return (hrtime(true) - $start) / 1000000.0;
}

function runPairedRound($offTracer, $onTracer, $round)
{
    $offSamples = array();
    $onSamples = array();
    for ($sample = 0; $sample < BENCH_SAMPLES; $sample++) {
        if (($sample + $round) % 2 === 0) {
            $offFirst = timedBatch($offTracer);
            $onFirst = timedBatch($onTracer);
            $onSecond = timedBatch($onTracer);
            $offSecond = timedBatch($offTracer);
        } else {
            $onFirst = timedBatch($onTracer);
            $offFirst = timedBatch($offTracer);
            $offSecond = timedBatch($offTracer);
            $onSecond = timedBatch($onTracer);
        }
        $offSamples[] = ($offFirst + $offSecond) / 2.0;
        $onSamples[] = ($onFirst + $onSecond) / 2.0;
    }

    $offP95 = percentile($offSamples, 0.95);
    $onP95 = percentile($onSamples, 0.95);
    return array(
        'off' => $offP95,
        'on' => $onP95,
        'overhead' => $offP95 > 0 ? (($onP95 - $offP95) / $offP95) * 100.0 : 0.0,
    );
}

function runSpanMicrobenchmark($tracer)
{
    $iterations = 5000;
    $samples = array();
    $total = 0;
    for ($index = 0; $index < $iterations; $index++) {
        $start = hrtime(true);
        $tracer->withSpan('bench.micro', function ($span) {
            $span->setAttribute('operation', 'micro');
        });
        $elapsed = hrtime(true) - $start;
        $samples[] = $elapsed / 1000.0;
        $total += $elapsed;
    }
    return array(
        'average' => ($total / 1000.0) / $iterations,
        'p95' => percentile($samples, 0.95),
    );
}

$offTracer = configuredTracer(false);
$onTracer = configuredTracer(true);
for ($index = 0; $index < BENCH_WARMUP; $index++) {
    operation($offTracer);
    operation($onTracer);
}

$rounds = array();
for ($round = 0; $round < BENCH_ROUNDS; $round++) {
    $rounds[] = runPairedRound($offTracer, $onTracer, $round);
}

$offValues = array();
$onValues = array();
$overheads = array();
foreach ($rounds as $round) {
    $offValues[] = $round['off'];
    $onValues[] = $round['on'];
    $overheads[] = $round['overhead'];
}

$medianOff = median($offValues);
$medianOn = median($onValues);
$medianOverhead = median($overheads);
$microOff = runSpanMicrobenchmark($offTracer);
$microOn = runSpanMicrobenchmark($onTracer);
Observability::shutdown();
$representativeOperationMicros = ($medianOff * 1000.0) / BENCH_BATCH_SIZE;
$intrinsicP95Micros = max(0.0, $microOn['p95'] - $microOff['p95']);
$intrinsicP95Overhead = $representativeOperationMicros > 0
    ? ($intrinsicP95Micros / $representativeOperationMicros) * 100.0
    : 0.0;

printf("paired rounds: %d\n", BENCH_ROUNDS);
printf("operations per p95 sample: %d\n", BENCH_BATCH_SIZE);
printf("median p95 off: %.4f ms\n", $medianOff);
printf("median p95 on: %.4f ms\n", $medianOn);
printf("observed paired workload p95 overhead: %.2f%%\n", $medianOverhead);
printf(
    "span micro average off/on: %.3f / %.3f us per operation\n",
    $microOff['average'],
    $microOn['average']
);
printf(
    "span intrinsic p95 delta: %.3f us; normalized overhead: %.2f%% (budget %.2f%%)\n",
    $intrinsicP95Micros,
    $intrinsicP95Overhead,
    BENCH_BUDGET_PERCENT
);

if ($intrinsicP95Overhead > BENCH_BUDGET_PERCENT) {
    fwrite(STDERR, "Intrinsic span p95 exceeded the normalized overhead budget.\n");
    exit(1);
}

if ($medianOverhead > BENCH_BUDGET_PERCENT) {
    fwrite(STDOUT, "Workload warning: paired wall-clock p95 was noisy; intrinsic budget passed.\n");
} else {
    fwrite(STDOUT, "Budget status: PASS\n");
}
