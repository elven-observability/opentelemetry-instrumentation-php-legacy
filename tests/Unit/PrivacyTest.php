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

    public function testKeyPlanMemoizationStillScansEachValueAndStaysConsistent(): void
    {
        $redactor = new AttributeRedactor(EnvConfigResolver::resolve());

        // A non-special ("scan") key must be re-scanned for every value, not
        // cached by value — only the per-key plan is memoized.
        self::assertSame('clean-route', $redactor->redactValue('custom.note', 'clean-route'));
        self::assertSame('[REDACTED_JWT]', $redactor->redactValue('custom.note', 'eyJhbGci.eyJpc3M.SflKxw'));
        self::assertSame('user a [REDACTED_EMAIL] paid', $redactor->redactValue('custom.note', 'user a a@example.com paid'));
        self::assertSame('clean-again', $redactor->redactValue('custom.note', 'clean-again'));

        // A sensitive key stays redacted across repeated calls (cache must not corrupt).
        self::assertSame('[REDACTED]', $redactor->redactValue('password', 'first'));
        self::assertSame('[REDACTED]', $redactor->redactValue('password', 'second'));

        // User-id key keeps hashing (stable, non-raw) on repeated calls.
        $h1 = $redactor->redactValue('user.id', '42');
        $h2 = $redactor->redactValue('user.id', '42');
        self::assertSame($h1, $h2);
        self::assertNotSame('42', $h1);
    }

    public function testRedactSensitiveTextPreGatesPreserveDetection(): void
    {
        // Mixed-case Bearer, embedded JWT, email, CPF and card must still be caught
        // after the cheap substring pre-checks.
        self::assertSame('Bearer [REDACTED]', UrlSanitizer::redactSensitiveText('bEaReR abc.def-123'));
        self::assertStringContainsString('[REDACTED_JWT]', UrlSanitizer::redactSensitiveText('t eyJa.eyJb.sig x'));
        self::assertStringContainsString('[REDACTED_EMAIL]', UrlSanitizer::redactSensitiveText('mail a@b.co here'));
        self::assertStringContainsString('[REDACTED_CPF]', UrlSanitizer::redactSensitiveText('doc 123.456.789-10'));
        self::assertStringContainsString('[REDACTED_CARD]', UrlSanitizer::redactSensitiveText('pan 4111 1111 1111 1111'));

        // Clean values (incl. ones with short digit runs) are returned untouched.
        self::assertSame('kontik-zupper-api-v14', UrlSanitizer::redactSensitiveText('kontik-zupper-api-v14'));
        self::assertSame('/rest/v2/aerial/search', UrlSanitizer::redactSensitiveText('/rest/v2/aerial/search'));
        self::assertSame('status-200', UrlSanitizer::redactSensitiveText('status-200'));
        self::assertSame('', UrlSanitizer::redactSensitiveText(''));
    }

    public function testGlobalRedactionOffLeavesSpanLogAndHeaderValuesRaw(): void
    {
        $redactor = new AttributeRedactor(EnvConfigResolver::resolve(array('redaction_enabled' => false)));

        self::assertSame('Bearer secret', $redactor->redactValue('http.request.header.authorization', 'Bearer secret'));
        self::assertSame('42', $redactor->redactValue('user.id', '42'));
        self::assertSame('select * from users where email="a@example.com"', $redactor->redactValue(
            'db.statement',
            'select * from users where email="a@example.com"'
        ));
        self::assertSame('SQL failed for a@example.com', $redactor->redactValue(
            'exception.message',
            'SQL failed for a@example.com'
        ));
        self::assertSame(array('Authorization' => 'Bearer secret'), $redactor->redactHeaders(array(
            'Authorization' => 'Bearer secret',
        )));
    }

    public function testGlobalRedactionOffDoesNotDisableMetricLabelAllowlist(): void
    {
        $redactor = new AttributeRedactor(EnvConfigResolver::resolve(array('redaction_enabled' => false)));
        $labels = $redactor->redactMetricLabels(array(
            'operation' => 'order-ABCD1234EFGH5678',
            'request_id' => 'must-not-leak',
        ), array('operation'));

        self::assertSame('{id}', $labels['operation']);
        self::assertArrayNotHasKey('request_id', $labels);
    }
}
