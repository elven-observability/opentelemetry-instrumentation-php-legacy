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

    /** Find the elven.php.request.errors points and return [error_category => total]. */
    private function requestErrorCategories(): array
    {
        $byCat = array();
        foreach (Observability::metrics()->collect() as $metric) {
            if ($metric['name'] !== 'elven.php.request.errors') {
                continue;
            }
            foreach ($metric['points'] as $point) {
                $cat = isset($point['attributes']['error_category']) ? $point['attributes']['error_category'] : '?';
                $byCat[$cat] = (isset($byCat[$cat]) ? $byCat[$cat] : 0) + $point['value'];
            }
        }
        return $byCat;
    }

    /** Attribute sets of every elven.php.request.errors point, keyed by error_type. */
    private function requestErrorAttributes(array $metrics): array
    {
        $byType = array();
        foreach ($metrics as $metric) {
            if ($metric['name'] !== 'elven.php.request.errors') {
                continue;
            }
            foreach ($metric['points'] as $point) {
                $type = isset($point['attributes']['error_type']) ? $point['attributes']['error_type'] : '?';
                $byType[$type] = $point['attributes'];
            }
        }
        return $byType;
    }

    /** Distinct route labels seen on a given metric. */
    private function routeLabels(array $metrics, string $name): array
    {
        $routes = array();
        foreach ($metrics as $metric) {
            if ($metric['name'] !== $name) {
                continue;
            }
            foreach ($metric['points'] as $point) {
                if (isset($point['attributes']['route'])) {
                    $routes[$point['attributes']['route']] = true;
                }
            }
        }
        return array_keys($routes);
    }

    public function testErrorCategorySeparatesTechnicalFromClient(): void
    {
        // 4xx -> client
        HttpServerInstrumentation::instrument('GET /forbidden', function () {
            return 'no';
        }, function () {
            return 403;
        });
        self::assertSame(array('client' => 1.0), $this->requestErrorCategories());

        // 5xx -> technical
        HttpServerInstrumentation::instrument('GET /boom', function () {
            return 'no';
        }, function () {
            return 500;
        });
        self::assertSame(array('technical' => 1.0), $this->requestErrorCategories());

        // thrown handler -> technical
        try {
            HttpServerInstrumentation::instrument('GET /throws', function () {
                throw new \RuntimeException('boom');
            });
            self::fail('should rethrow');
        } catch (\RuntimeException $e) {
            // expected
        }
        self::assertSame(array('technical' => 1.0), $this->requestErrorCategories());
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

    public function testClientErrorCarriesTheRouteAndMatchesTheDurationHistogram(): void
    {
        HttpServerInstrumentation::instrument('/rest/v2/aerial/search', function () {
            return 'denied';
        }, function () {
            return 403;
        });

        $metrics = Observability::metrics()->collect();
        $attrs = $this->requestErrorAttributes($metrics);
        self::assertArrayHasKey('http_4xx', $attrs);
        self::assertSame('/rest/v2/aerial/search', $attrs['http_4xx']['route']);
        // The error counter must join with the duration histogram on route.
        self::assertSame(
            $this->routeLabels($metrics, 'http.server.request.duration'),
            $this->routeLabels($metrics, 'elven.php.request.errors')
        );
    }

    public function testServerErrorCarriesTheRoute(): void
    {
        HttpServerInstrumentation::instrument('/rest/v2/booking/reserve', function () {
            return 'fail';
        }, function () {
            return 503;
        });

        $attrs = $this->requestErrorAttributes(Observability::metrics()->collect());
        self::assertArrayHasKey('http_5xx', $attrs);
        self::assertSame('/rest/v2/booking/reserve', $attrs['http_5xx']['route']);
        self::assertSame('503', $attrs['http_5xx']['status_code']);
    }

    public function testThrownHandlerCarriesTheRouteAndTheResolvedStatusCode(): void
    {
        try {
            HttpServerInstrumentation::instrument('/rest/v2/payment/authorize', function () {
                throw new \RuntimeException('handler boom');
            }, function () {
                return 500;
            });
            self::fail('exception should propagate');
        } catch (\RuntimeException $e) {
            // expected
        }

        $attrs = $this->requestErrorAttributes(Observability::metrics()->collect());
        self::assertArrayHasKey('exception', $attrs);
        self::assertSame('/rest/v2/payment/authorize', $attrs['exception']['route']);
        // The exception branch must report a status code like the 4xx/5xx branches do.
        self::assertSame('500', $attrs['exception']['status_code']);
    }

    public function testDynamicRouteNeverLeaksIntoTheErrorLabel(): void
    {
        HttpServerInstrumentation::instrument(
            '/rest/v2/customer/1234567/order/9876543',
            function () {
                return 'boom';
            },
            function () {
                return 500;
            }
        );

        $attrs = $this->requestErrorAttributes(Observability::metrics()->collect());
        $route = $attrs['http_5xx']['route'];
        self::assertSame('/rest/v2/customer/{id}/order/{id}', $route);
        self::assertStringNotContainsString('1234567', $route);
        self::assertStringNotContainsString('9876543', $route);
    }

    public function testFinishWithoutRouteFallsBackToTheSanitizedRequestPath(): void
    {
        // Query string carries a token: parse_url drops it before normalization,
        // so it can never reach the label.
        $_SERVER['REQUEST_URI'] = '/rest/v2/customer/1234567/profile?token=eyJhbGciOi.J9.sig';
        $span = HttpServerInstrumentation::startFromGlobals();
        HttpServerInstrumentation::finish($span, 500);

        $attrs = $this->requestErrorAttributes(Observability::metrics()->collect());
        self::assertSame('/rest/v2/customer/{id}/profile', $attrs['http_5xx']['route']);

        unset($_SERVER['REQUEST_URI']);
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
