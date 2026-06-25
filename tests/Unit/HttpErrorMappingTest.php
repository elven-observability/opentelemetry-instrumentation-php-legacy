<?php

namespace Elven\Observability\PhpLegacy\Tests\Unit;

use Elven\Observability\PhpLegacy\Instrumentation\HttpServerInstrumentation;
use Elven\Observability\PhpLegacy\Observability;
use Elven\Observability\PhpLegacy\Tests\Support\Env;
use PHPUnit\Framework\TestCase;

final class HttpErrorMappingTest extends TestCase
{
    protected function setUp(): void
    {
        Env::reset();
        // Metrics stay enabled (no endpoint set) so collect() returns recorded
        // points; traces/logs off to keep the test hermetic.
        putenv('OTEL_TRACES_EXPORTER=none');
        putenv('OTEL_LOGS_EXPORTER=none');
        Observability::init(array(
            'service_name' => 'error-mapping-test',
            'service_namespace' => 'booking',
            'environment' => 'staging',
        ));
        if (function_exists('http_response_code')) {
            http_response_code(200);
        }
    }

    /** Find the elven.php.request.errors points and return [error_type => total]. */
    private function requestErrorPoints(): array
    {
        $byType = array();
        foreach (Observability::metrics()->collect() as $metric) {
            if ($metric['name'] !== 'elven.php.request.errors') {
                continue;
            }
            foreach ($metric['points'] as $point) {
                $type = isset($point['attributes']['error_type']) ? $point['attributes']['error_type'] : '?';
                $byType[$type] = (isset($byType[$type]) ? $byType[$type] : 0) + $point['value'];
            }
        }
        return $byType;
    }

    public function testSuccessfulRequestRecordsNoError(): void
    {
        HttpServerInstrumentation::instrument('GET /ok', function () {
            return 'ok';
        }, function () {
            return 200;
        });

        self::assertSame(array(), $this->requestErrorPoints());
    }

    public function testClientErrorIsCountedAsHttp4xxWithoutSpanError(): void
    {
        $captured = null;
        HttpServerInstrumentation::instrument('GET /forbidden', function ($span) use (&$captured) {
            $captured = $span;
            return 'denied';
        }, function () {
            return 403;
        });

        $errors = $this->requestErrorPoints();
        self::assertArrayHasKey('http_4xx', $errors);
        self::assertSame(1.0, $errors['http_4xx']);
        self::assertArrayNotHasKey('http_5xx', $errors);
        // 4xx is a client error: the SERVER span must NOT be marked ERROR.
        self::assertNotSame('ERROR', $captured->statusCode());
    }

    public function testServerErrorIsCountedAsHttp5xxAndMarksSpanError(): void
    {
        $captured = null;
        HttpServerInstrumentation::instrument('GET /boom', function ($span) use (&$captured) {
            $captured = $span;
            return 'fail';
        }, function () {
            return 503;
        });

        $errors = $this->requestErrorPoints();
        self::assertArrayHasKey('http_5xx', $errors);
        self::assertSame(1.0, $errors['http_5xx']);
        self::assertSame('ERROR', $captured->statusCode());
    }

    public function testThrownHandlerIsCountedOnceAsExceptionNotDoubleWithHttp5xx(): void
    {
        try {
            HttpServerInstrumentation::instrument('GET /throws', function () {
                throw new \RuntimeException('handler boom');
            }, function () {
                // Even if the resolver reports 500, a thrown handler must be
                // counted exactly once as 'exception', never also as http_5xx.
                return 500;
            });
            self::fail('exception should propagate');
        } catch (\RuntimeException $e) {
            self::assertSame('handler boom', $e->getMessage());
        }

        $errors = $this->requestErrorPoints();
        self::assertSame(array('exception' => 1.0), $errors);
    }

    public function testIsBotPromotedToRequestMetricsAndSpan(): void
    {
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
        $captured = null;
        HttpServerInstrumentation::instrument('GET /search', function ($span) use (&$captured) {
            $captured = $span;
            return 'ok';
        }, function () {
            return 200;
        });

        $attrs = $captured->attributes();
        self::assertSame('true', $attrs['client.is_bot']);
        self::assertSame('search_engine', $attrs['bot.category']);

        // is_bot must appear as a request-metric label (e.g. on the duration histogram).
        $foundIsBotLabel = false;
        foreach (Observability::metrics()->collect() as $metric) {
            foreach ($metric['points'] as $point) {
                if (isset($point['attributes']['is_bot'])) {
                    $foundIsBotLabel = true;
                    self::assertSame('true', $point['attributes']['is_bot']);
                }
                self::assertArrayNotHasKey('bot.category', $point['attributes'], 'bot.category must stay off metric labels');
                self::assertArrayNotHasKey('user_agent.original', $point['attributes'], 'raw UA must never be a metric label');
            }
        }
        self::assertTrue($foundIsBotLabel, 'is_bot should be promoted to request metric labels');

        unset($_SERVER['HTTP_USER_AGENT']);
    }
}
