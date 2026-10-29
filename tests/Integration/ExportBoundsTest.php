<?php

namespace Elven\Observability\PhpLegacy\Tests\Integration;

use Elven\Observability\PhpLegacy\Export\BoundedHttpExchange;
use Elven\Observability\PhpLegacy\Export\ExportBudget;
use Elven\Observability\PhpLegacy\Export\LimitedJson;
use Elven\Observability\PhpLegacy\Observability;
use PHPUnit\Framework\TestCase;

final class ExportBoundsTest extends TestCase
{
    private $process;
    private $store;
    private $port;
    private $certificate;

    protected function tearDown(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        foreach (array($this->store, $this->store . '.ready', $this->certificate) as $file) {
            if ($file && is_file($file)) {
                unlink($file);
            }
        }
    }

    private function start($mode)
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        $this->port = (int) substr(strrchr($name, ':'), 1);
        $this->store = tempnam(sys_get_temp_dir(), 'elven-bounds-');
        if ($mode === 'tls') {
            $key = openssl_pkey_new(array('private_key_bits' => 2048));
            $csr = openssl_csr_new(array('commonName' => 'localhost'), $key);
            $cert = openssl_csr_sign($csr, null, $key, 1);
            openssl_x509_export($cert, $pem);
            openssl_pkey_export($key, $private);
            $this->certificate = tempnam(sys_get_temp_dir(), 'elven-test-tls-');
            file_put_contents($this->certificate, $pem . $private);
        }
        $command = 'exec ' . escapeshellarg(PHP_BINARY) . ' '
            . escapeshellarg(dirname(__DIR__) . '/Support/adversarial-collector.php') . ' '
            . $this->port . ' ' . escapeshellarg($mode) . ' ' . escapeshellarg($this->store)
            . ' ' . escapeshellarg($this->certificate ?: '');
        $this->process = proc_open($command, array(1 => array('file', '/dev/null', 'w'),
            2 => array('file', '/dev/null', 'w')), $pipes);
        for ($i = 0; $i < 200 && !is_file($this->store . '.ready'); $i++) {
            usleep(10000);
        }
        self::assertFileExists($this->store . '.ready');
        return ($mode === 'tls' ? 'https' : 'http') . '://127.0.0.1:' . $this->port . '/v1/traces';
    }

    public function testHealthyCurlAndStream()
    {
        $url = $this->start('healthy');
        foreach (array(false, true) as $stream) {
            $response = (new BoundedHttpExchange())->post($url, '{}', array('Content-Type' => 'application/json'), 500, $stream);
            self::assertTrue($response['ok']);
            self::assertSame('{}', $response['body']);
        }
    }

    /** @dataProvider hostileResponses */
    public function testHostileResponseIsBounded($mode, $stream)
    {
        $url = $this->start($mode);
        $start = microtime(true);
        $ok = false;
        try {
            $ok = (new BoundedHttpExchange())->post($url, '{}', array(), 60, $stream)['ok'];
        } catch (\Throwable $ignored) {
        }
        self::assertFalse($ok);
        // A regression guard against the 2-second fixture, not an overhead SLA.
        self::assertLessThan(1.0, microtime(true) - $start);
    }

    public static function hostileResponses(): array
    {
        $cases = array();
        foreach (array('stall', 'drip-header', 'drip-body', 'large-header', 'large-body', 'large-close-body', 'chunked', 'tls') as $mode) {
            foreach (array(false, true) as $stream) {
                $cases[$mode . ($stream ? '-stream' : '-curl')] = array($mode, $stream);
            }
        }
        return $cases;
    }

    public function testBudgetAndJsonCeilings()
    {
        $budget = new ExportBudget(20, 1024);
        self::assertTrue($budget->reserve(1024));
        self::assertFalse($budget->reserve(1));
        $budget->release(1024);
        self::assertSame(0, $budget->queuedBytes());
        $owner = $budget->enter();
        self::assertTrue($budget->spendWire(ExportBudget::WIRE_BYTES));
        self::assertFalse($budget->spendWire(1));
        $budget->leave($owner);
        self::assertTrue($budget->enter());
        self::assertTrue($budget->spendWire(1));
        $budget->leave(true);
        self::assertNull(LimitedJson::encode(array('large' => str_repeat('x', ExportBudget::WIRE_BYTES + 1))));
        $recursive = array();
        $recursive['self'] = &$recursive;
        self::assertNull(LimitedJson::encode($recursive));
        self::assertSame('{"safe":[1,true,"value"]}', LimitedJson::encode(array('safe' => array(1, true, 'value'))));
    }

    public function testFailedFlushRetainsOnlyTheSharedQueueBudget(): void
    {
        Observability::resetForTests();
        $handle = Observability::init(array('enabled' => true, 'endpoint' => 'http://127.0.0.1:1',
            'logs_exporter' => 'otlp', 'max_spans_per_request' => 2048, 'timeout_millis' => 20));
        for ($i = 0; $i < 300; $i++) {
            Observability::tracer()->withSpan('bounded', function ($span) {
                for ($j = 0; $j < 64; $j++) {
                    $span->setAttribute('field.' . $j, str_repeat('x', 2048));
                }
            });
            Observability::logs()->emit('INFO', str_repeat('x', 8192));
        }
        $budget = Observability::metrics()->budget();
        self::assertGreaterThan(1000000, $budget->queuedBytes());
        self::assertLessThanOrEqual(ExportBudget::QUEUE_BYTES, $budget->queuedBytes());
        self::assertFalse($handle->forceFlush());
        self::assertLessThanOrEqual(ExportBudget::QUEUE_BYTES, $budget->queuedBytes());
        $handle->shutdown();
        Observability::resetForTests();
    }

    public function testHealthyChunkedResponseWorksWithoutCurl(): void
    {
        $url = $this->start('healthy-chunked');
        $response = (new BoundedHttpExchange())->post($url, '{}', array(), 500, true);
        self::assertTrue($response['ok']);
        self::assertSame('{}', $response['body']);
    }
}
