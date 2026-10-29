<?php

namespace Elven\Observability\PhpLegacy\Tests\Integration;

use Elven\Observability\PhpLegacy\Export\HttpJsonClient;
use PHPUnit\Framework\TestCase;

final class RedirectSafetyTest extends TestCase
{
    public function testStreamAndCurlDoNotFollowCollectorRedirects(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr(strrchr($name, ':'), 1);
        $store = tempnam(sys_get_temp_dir(), 'elven-redirect-');
        $command = 'exec ' . escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port . ' '
            . escapeshellarg(dirname(__DIR__) . '/Support/redirect-collector.php');
        $process = proc_open(
            $command,
            array(1 => array('file', '/dev/null', 'w'),
            2 => array('file', '/dev/null', 'w')),
            $pipes,
            null,
            array_merge(getenv(), array('ELVEN_TEST_REDIRECT_STORE' => $store))
        );
        self::assertIsResource($process);
        try {
            $ready = false;
            for ($i = 0; $i < 100; $i++) {
                $probe = @fsockopen('127.0.0.1', $port);
                if ($probe) {
                    fclose($probe);
                    $ready = true;
                    break;
                }
                usleep(20000);
            }
            self::assertTrue($ready);
            $client = new HttpJsonClient(array('Authorization' => 'Bearer synthetic-audit-only'), 500);
            $method = new \ReflectionMethod($client, 'postWithStream');
            if (PHP_VERSION_ID < 80100) {
                $method->setAccessible(true);
            }
            $result = $method->invoke($client, 'http://127.0.0.1:' . $port . '/redirect', '{}');
            self::assertFalse($result['ok']);
            if (function_exists('curl_init')) {
                self::assertFalse($client->post('http://127.0.0.1:' . $port . '/redirect', array()));
            }
            self::assertStringNotContainsString('/sink', file_get_contents($store));
            self::assertFalse($client->post('file:///etc/hosts', array()));
        } finally {
            proc_terminate($process);
            proc_close($process);
            @unlink($store);
        }
    }
}
