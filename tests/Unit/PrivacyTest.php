<?php

namespace Elven\Observability\PhpLegacy\Tests\Unit;

use Elven\Observability\PhpLegacy\Config\EnvConfigResolver;
use Elven\Observability\PhpLegacy\Privacy\AttributeRedactor;
use Elven\Observability\PhpLegacy\Privacy\DbStatementSanitizer;
use Elven\Observability\PhpLegacy\Privacy\UrlSanitizer;
use Elven\Observability\PhpLegacy\Tests\Support\Env;
use PHPUnit\Framework\TestCase;

final class PrivacyTest extends TestCase
{
    protected function setUp(): void
    {
        Env::reset();
    }

    public function testSensitiveUrlParamsAreRedacted(): void
    {
        $url = UrlSanitizer::sanitizeUrl('https://api.example.test/order/123456/customer/a@example.com/token/abc?token=abc&email=a@example.com&page=1');

        self::assertStringContainsString('token=%5BREDACTED%5D', $url);
        self::assertStringContainsString('email=%5BREDACTED%5D', $url);
        self::assertStringContainsString('/order/{id}', $url);
        self::assertStringContainsString('/customer/{email}/token/{redacted}', $url);
    }

    public function testDbStatementSanitizerRemovesLiterals(): void
    {
        $sql = "select * from users where email='a@example.com' and cpf='123.456.789-10' and id=123";

        self::assertSame('select * from users where email=? and cpf=? and id=?', DbStatementSanitizer::sanitize($sql));
        self::assertSame('SELECT users', DbStatementSanitizer::summary($sql));
    }

    public function testAttributeRedactorHashesUserAndRedactsTokens(): void
    {
        $redactor = new AttributeRedactor(EnvConfigResolver::resolve());

        self::assertSame('[REDACTED]', $redactor->redactValue('http.request.header.authorization', 'Bearer secret'));
        self::assertNotSame('42', $redactor->redactValue('user.id', '42'));
        self::assertSame('[REDACTED]', $redactor->redactValue('db.statement', 'select 1'));
        self::assertSame('[REDACTED]', $redactor->redactValue('exception.message', 'SQL failed for a@example.com'));
        self::assertSame('{id}', $redactor->redactMetricLabels(array(
            'operation' => 'order-ABCD1234EFGH5678',
        ), array('operation'))['operation']);
        self::assertSame('google_flights', $redactor->redactMetricLabels(array(
            'traffic_source' => 'Google Flights',
        ), array('traffic_source'))['traffic_source']);
    }
}
