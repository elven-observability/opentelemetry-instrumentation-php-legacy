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

    public function testDbStatementSanitizerHandlesEscapesCommentsAndDialectLiterals(): void
    {
        $sql = "SELECT * FROM users WHERE note='private\\'value' AND token=\$tag\$secret-value\$tag\$ "
            . "AND fingerprint=0xDEADBEEF AND score=1.5e10 # private-comment";

        $sanitized = DbStatementSanitizer::sanitize($sql);
        self::assertSame(
            'SELECT * FROM users WHERE note=? AND token=? AND fingerprint=? AND score=?',
            $sanitized
        );
        self::assertStringNotContainsString('private', $sanitized);
        self::assertStringNotContainsString('secret-value', $sanitized);

        self::assertSame('SELECT * FROM users WHERE token=?', DbStatementSanitizer::sanitize(
            "SELECT * FROM users WHERE token='unterminated-secret"
        ));
        self::assertSame('SELECT * FROM users', DbStatementSanitizer::sanitize(
            'SELECT * FROM users /* unterminated private comment'
        ));
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
        self::assertSame('other', $redactor->redactMetricLabels(array(
            'cache_name' => 'customer-ABCD1234EFGH5678',
        ), array('cache_name'))['cache_name']);
        // A consumer's own outcome vocabulary now SURVIVES (see
        // testOutcomeLabelsKeepTheConsumerVocabulary). It used to collapse to
        // `other`, which silently emptied every consumer funnel dashboard.
        self::assertSame('customer-specific-result', $redactor->redactMetricLabels(array(
            'result' => 'customer-specific-result',
        ), array('result'))['result']);
    }

    /**
     * `result`, `error_category` and `dependency_type` are the only labels a
     * consumer has for the outcome of its OWN operation. They used to be closed
     * enums owned by this library, and anything outside the list was silently
     * REPLACED by `other` inside `MetricFacade::point()` -- so a consumer's
     * `sum by (result)` returned `success` and `other`, and nothing failed.
     *
     * @dataProvider consumerOutcomeVocabularyProvider
     */
    public function testOutcomeLabelsKeepTheConsumerVocabulary(string $key, string $value): void
    {
        $redactor = new AttributeRedactor(EnvConfigResolver::resolve());

        self::assertSame(
            $value,
            $redactor->redactMetricLabels(array($key => $value), array($key))[$key],
            sprintf('`%s=%s` was rewritten by the redactor', $key, $value)
        );
    }

    public function consumerOutcomeVocabularyProvider(): array
    {
        return array(
            // this library's own values must keep working -- positive control
            'result: success'               => array('result', 'success'),
            'result: hit'                   => array('result', 'hit'),
            'result: miss'                  => array('result', 'miss'),
            'error_category: technical'     => array('error_category', 'technical'),
            'dependency_type: http'         => array('dependency_type', 'http'),
            'dependency_type: soap'         => array('dependency_type', 'soap'),
            // and the consumer vocabulary that used to disappear
            'result: business_reject'       => array('result', 'business_reject'),
            'result: dependency_error'      => array('result', 'dependency_error'),
            'result: business_error'        => array('result', 'business_error'),
            'result: requeue'               => array('result', 'requeue'),
            'result: exhausted'             => array('result', 'exhausted'),
            'result: degraded'              => array('result', 'degraded'),
            'result: suggested'             => array('result', 'suggested'),
            'result: variant'               => array('result', 'variant'),
            'result: approved'              => array('result', 'approved'),
            'error_category: business'      => array('error_category', 'business'),
            'dependency_type: payment_gateway' => array('dependency_type', 'payment_gateway'),
        );
    }

    /**
     * Letting the vocabulary through must NOT let cardinality through. What
     * actually blows a metric up is an identifier, not a business word -- and the
     * id check that already guards `operation` and `dependency_name` guards these
     * three now too.
     *
     * @dataProvider outcomeLabelBoundsProvider
     */
    public function testOutcomeLabelsStayBounded(string $value, string $expected, string $why): void
    {
        $redactor = new AttributeRedactor(EnvConfigResolver::resolve());

        self::assertSame(
            $expected,
            $redactor->redactMetricLabels(array('result' => $value), array('result'))['result'],
            $why
        );
    }

    public function outcomeLabelBoundsProvider(): array
    {
        return array(
            'identifier collapses' => array(
                'order-8F2A19C4B7E3D5A6', '{id}', 'an identifier must never become a series',
            ),
            'bare order number collapses' => array(
                'reserva 201211', '{id}', 'an order number must never become a series',
            ),
            // 🔴 0.7.0 shipped with only the case above as the control, and the
            // numeric-run check was `/\b\d{4,}\b/`: it needs a word boundary and `_`
            // is a word character, so the shape a consumer builds most often went
            // through as a label value. Space, `-` and `.` were caught; this was not.
            'order number joined by underscore collapses' => array(
                'reserva_201211', '{id}', 'an order number must never become a series, whatever joins it',
            ),
            'uuid collapses' => array(
                '3f2504e0-4f89-41d3-9a0c-0305e82c3301', '{id}', 'a uuid must never become a series',
            ),
            // Redacted UPSTREAM by `redactValue()`, so the marker that arrives
            // here is `[REDACTED_EMAIL]` -- and it must survive byte for byte.
            // Lower-casing markers would give one thing two spellings.
            'email is redacted, marker keeps its casing' => array(
                'a@example.com', '[REDACTED_EMAIL]', 'an address must never become a series',
            ),
            'empty becomes unknown' => array(
                '   ', 'unknown', 'an empty label must not become the empty string',
            ),
            'casing and spacing are folded' => array(
                'Business Reject', 'business_reject', 'one outcome must not split into several series',
            ),
            'free text is capped at 40' => array(
                'this is not an outcome it is a whole sentence about what happened',
                'this_is_not_an_outcome_it_is_a_whole_sen',
                'an outcome token that long is free text',
            ),
        );
    }

    /**
     * A NAME that carries a small number is not an identifier.
     *
     * Before 0.7.1 any 16+ character letter+digit token became `{id}` -- including
     * `Missing404Exception`, which is what a consumer's failing job reported as
     * `error_type`, so the only clue it left was erased. This is not a 0.7.0
     * regression: `error_type`, `operation` and `dependency_name` went through the
     * same check before.
     *
     * @dataProvider namesWithNumbersProvider
     */
    public function testNamesWithASmallNumberAreNotMistakenForIdentifiers(string $key, string $value): void
    {
        $redactor = new AttributeRedactor(EnvConfigResolver::resolve());

        self::assertSame(
            $value,
            $redactor->redactMetricLabels(array($key => $value), array($key))[$key],
            sprintf('`%s=%s` is a name, not an identifier', $key, $value)
        );
    }

    public function namesWithNumbersProvider(): array
    {
        return array(
            'error_type: elasticsearch missing index' => array('error_type', 'Missing404Exception'),
            'error_type: elasticsearch bad request'   => array('error_type', 'BadRequest400Exception'),
            'error_type: http2 protocol'              => array('error_type', 'Http2ProtocolError'),
            'operation: versioned flag name'          => array('operation', 'allow_bearer_session_recovery_v2'),
            'dependency_name: collation-like name'    => array('dependency_name', 'utf8mb4_unicode_ci_collation'),
            'result: experiment arm'                  => array('result', 'checkout_revamp_v2_treatment'),
        );
    }

    /**
     * The negative control for the test above: relaxing the rule for names must not
     * let an opaque token through, on any of the labels it applies to.
     *
     * @dataProvider opaqueTokensProvider
     */
    public function testOpaqueTokensStillCollapse(string $key, string $value): void
    {
        $redactor = new AttributeRedactor(EnvConfigResolver::resolve());

        self::assertSame(
            '{id}',
            $redactor->redactMetricLabels(array($key => $value), array($key))[$key],
            sprintf('`%s=%s` must never become a series', $key, $value)
        );
    }

    public function opaqueTokensProvider(): array
    {
        return array(
            'operation: order code'             => array('operation', 'order_8F2A19C4B7E3'),
            'operation: base62 token'           => array('operation', 'aZ3kP9qL2mX7vB1n'),
            'error_type: uuid'                  => array('error_type', '3f2504e0-4f89-41d3-9a0c-0305e82c3301'),
            'dependency_name: hex run'          => array('dependency_name', '9f86d081884c7d65'),
            'dependency_name: number by _'      => array('dependency_name', 'shard_20260910'),
            'error_type: number glued to word'  => array('error_type', 'retry4821'),
        );
    }

    /**
     * The placeholder names what was found, and a CPF is only a CPF when it stands
     * alone.
     *
     * `sanitizePathSegment()` tested the CPF shape, unanchored, BEFORE the UUID: the
     * last UUID group `446655440000` holds eleven digits in a row, so a UUID was
     * reported as `{cpf}`, and so was any longer digit run (a card number). Every
     * one of these was redacted before and still is -- only the name of the
     * placeholder changes, and a segment is never let through raw.
     *
     * @dataProvider segmentPlaceholderProvider
     */
    public function testSegmentPlaceholderNamesWhatWasFound(string $key, string $value, string $expected): void
    {
        $redactor = new AttributeRedactor(EnvConfigResolver::resolve());

        self::assertSame(
            $expected,
            $redactor->redactMetricLabels(array($key => $value), array($key))[$key],
            sprintf('`%s=%s` must be redacted as %s', $key, $value, $expected)
        );
    }

    public function segmentPlaceholderProvider(): array
    {
        return array(
            // were `{cpf}` on 5de2a5e
            'operation: uuid is not a cpf'            => array('operation', 'order_550e8400-e29b-41d4-a716-446655440000', '{id}'),
            'operation: card-length run is not a cpf' => array('operation', 'pedido_4111111111111111', '{id}'),
            'operation: cpf shape inside more digits' => array('operation', 'doc_123.456.789-100', '{id}'),
            'route: uuid segment is not a cpf'        => array('route', '/order/550e8400-e29b-41d4-a716-446655440000/items', '/order/{id}/items'),
            // positive control: a CPF standing alone is still named `{cpf}`
            'operation: bare cpf digits'              => array('operation', 'doc_12345678901', '{cpf}'),
            'operation: formatted cpf'                => array('operation', 'doc_123.456.789-10', '{cpf}'),
            'route: cpf glued to a word'              => array('route', '/customer/doc_12345678901/orders', '/customer/{cpf}/orders'),
        );
    }

    /**
     * An identifier glued to a key word is still an identifier.
     *
     * `sanitizePath()` reads a segment that contains a key word (`session`,
     * `token`, `cpf`, `email`, `card`...) as the NAME of the next segment and
     * redacts that one -- but it skipped the identifier checks on the key segment
     * itself. `cpf_12345678901` went through as a label value on every version, and
     * `session_20260910` started going through once the letter+digit rule was
     * relaxed (0.7.0 still caught it as a 16+ character opaque token).
     *
     * `result`, `error_category` and `dependency_type` go through the same
     * `sanitizePath()` (in `normalizeVocabularyLabel()`), so they are covered too.
     *
     * @dataProvider identifiersGluedToAKeyWordProvider
     */
    public function testIdentifiersGluedToAKeyWordStillCollapse(string $key, string $value, string $expected): void
    {
        $redactor = new AttributeRedactor(EnvConfigResolver::resolve());

        self::assertSame(
            $expected,
            $redactor->redactMetricLabels(array($key => $value), array($key))[$key],
            sprintf('`%s=%s` carries an identifier and must never become a series', $key, $value)
        );
    }

    public function identifiersGluedToAKeyWordProvider(): array
    {
        return array(
            'operation: order number after session' => array('operation', 'session_20260910', '{id}'),
            'operation: cpf after the cpf key word'  => array('operation', 'cpf_12345678901', '{cpf}'),
            'error_type: number after email'         => array('error_type', 'email_201211', '{id}'),
            'dependency_name: number glued to card'  => array('dependency_name', 'card201211', '{id}'),
            'route: id segment named like a key'     => array('route', '/order/session_20260910', '/order/{id}'),
            'route: key segment keeps marking next'  => array('route', '/token_20260910/abc', '/{id}/{redacted}'),
            'result: vocabulary label goes through it too' => array('result', 'Session_20260910', '{id}'),
            // Was `{id}` before this check (the whole-value UUID rule) and must stay
            // `{id}`: the key segment must not rename a placeholder to `{cpf}`.
            'operation: uuid after session stays an id' => array('operation', 'session_550e8400-e29b-41d4-a716-446655440000', '{id}'),
            // `redactValue()` runs `redactSensitiveText()` on the label before
            // `sanitizePath()`, so the JWT is replaced in place and the word stays.
            'operation: jwt glued to the token key word' => array(
                'operation', 'token_eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.abcDEF', 'token_[REDACTED_JWT]',
            ),
        );
    }

    /**
     * Positive control for the test above: checking the key segment must not
     * erase a NAME that merely contains a key word, and the key segment still
     * marks the next one as its value.
     */
    public function testKeyWordNamesSurviveAndStillMarkTheNextSegment(): void
    {
        $redactor = new AttributeRedactor(EnvConfigResolver::resolve());

        $names = array(
            array('operation', 'payment.credit_card.authorize.full'),
            array('operation', 'feature_flag.credit_card_payment'),
            array('error_type', 'invalid_card'),
            array('dependency_name', 'token-service'),
        );
        foreach ($names as $case) {
            list($key, $value) = $case;
            self::assertSame(
                $value,
                $redactor->redactMetricLabels(array($key => $value), array($key))[$key],
                sprintf('`%s=%s` is a name, not an identifier', $key, $value)
            );
        }

        self::assertSame('/token/{redacted}', UrlSanitizer::sanitizePath('/token/abc'));
    }

    /**
     * A JWT glued to a word by `_` is still a JWT.
     *
     * Both JWT patterns started with `\b`, and `_` is a word character: in
     * `token_eyJ...` there is no word boundary before `eyJ`, so the whole token
     * went through `redactSensitiveText()` (span attributes, log fields, headers,
     * baggage, metric labels) and `sanitizePath()` (`url.path`) raw.
     *
     * @dataProvider jwtGluedToAWordProvider
     */
    public function testAJwtGluedToAWordIsStillRedacted(string $where, string $value, string $expected): void
    {
        if ($where === 'text') {
            $actual = UrlSanitizer::redactSensitiveText($value);
        } elseif ($where === 'path') {
            $actual = UrlSanitizer::sanitizePath($value);
        } else {
            $redactor = new AttributeRedactor(EnvConfigResolver::resolve());
            $actual = $redactor->redactMetricLabels(array($where => $value), array($where))[$where];
        }

        self::assertSame($expected, $actual, sprintf('%s `%s`', $where, $value));
    }

    public function jwtGluedToAWordProvider(): array
    {
        $jwt = 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.abcDEF';

        return array(
            // were raw on the previous source
            'text: glued by underscore'       => array('text', 'token_' . $jwt, 'token_[REDACTED_JWT]'),
            'path: key segment'               => array('path', '/api/token_' . $jwt . '/x', '/api/{token}/{redacted}'),
            'path: plain segment'             => array('path', '/api/x_' . $jwt, '/api/{token}'),
            'operation: glued to a plain word' => array('operation', 'x_' . $jwt, 'x_[REDACTED_JWT]'),
            // positive control: unchanged on both
            'text: separated by a space'      => array('text', 'token ' . $jwt, 'token [REDACTED_JWT]'),
            'text: word ending in eyJ'        => array('text', 'monkeyJar.config.v2', 'monkeyJar.config.v2'),
            'path: word ending in eyJ'        => array('path', '/heyJude.a.b', '/heyJude.a.b'),
        );
    }

    /**
     * An identifier after a DOT is still an identifier.
     *
     * The letter+digit rule only accepted `[A-Za-z0-9_-]` in the whole value and
     * the hex rule was anchored to the whole value, so an id after a dotted prefix
     * -- `'checkout.' . $token` -- was never checked: `checkout.8f2a19c4b7e3a1b2c3d4`
     * went through while `checkout_8F2A19C4B7E3A1B2` became `{id}`. Same class of
     * defect as the `_` in the four-digit check.
     *
     * The data sets exercise the two rules separately: `checkout.a1b2c3d4e5f6` is
     * only caught by the letter+digit rule (12 characters, too short for the hex
     * rule) and `checkout.deadbeefcafebabe` only by the hex rule (no digit).
     *
     * @dataProvider identifiersAfterADotProvider
     */
    public function testAnIdentifierAfterADotStillCollapses(string $key, string $value, string $expected): void
    {
        $redactor = new AttributeRedactor(EnvConfigResolver::resolve());

        self::assertSame(
            $expected,
            $redactor->redactMetricLabels(array($key => $value), array($key))[$key],
            sprintf('`%s=%s` carries an identifier and must never become a series', $key, $value)
        );
    }

    public function identifiersAfterADotProvider(): array
    {
        return array(
            'operation: hex id after a dot'          => array('operation', 'checkout.8f2a19c4b7e3a1b2c3d4', '{id}'),
            'operation: upper-case hex after a dot'  => array('operation', 'checkout.8F2A19C4B7E3A1B2', '{id}'),
            'operation: letter+digit rule only'      => array('operation', 'checkout.a1b2c3d4e5f6', '{id}'),
            'operation: hex rule only (no digit)'    => array('operation', 'checkout.deadbeefcafebabe', '{id}'),
            'error_type: id after a dot'             => array('error_type', 'Payment.8f2a19c4b7e3a1b2c3d4', '{id}'),
            'result: id after a dot'                 => array('result', 'checkout.8f2a19c4b7e3a1b2c3d4', '{id}'),
            'route: segment with an id after a dot'  => array('route', '/order.8f2a19c4b7e3a1b2c3d4/items', '/{id}/items'),
            'cache_name: id after a dot'             => array('cache_name', 'rates.8f2a19c4b7e3a1b2c3d4', 'other'),
        );
    }

    /**
     * Positive control for the test above: splitting on `.` must not erase a dotted
     * NAME. The last four go through the letter+digit gate (16+ characters with a
     * digit), so they are checked part by part and must survive that.
     *
     * @dataProvider dottedNamesProvider
     */
    public function testDottedNamesAreNotMistakenForIdentifiers(string $key, string $value): void
    {
        $redactor = new AttributeRedactor(EnvConfigResolver::resolve());

        self::assertSame(
            $value,
            $redactor->redactMetricLabels(array($key => $value), array($key))[$key],
            sprintf('`%s=%s` is a name, not an identifier', $key, $value)
        );
    }

    public function dottedNamesProvider(): array
    {
        return array(
            'operation: key word as the last word'  => array('operation', 'reservation.persist.email'),
            'operation: flag name'                  => array('operation', 'feature_flag.credit_card_payment'),
            'operation: short dotted name'          => array('operation', 'dsg.search.g3'),
            'operation: installments count'         => array('operation', 'funnel.payment.aerial.installments.1x'),
            'operation: dsg operation per airline'  => array('operation', 'dsg.reservaraereocomviagenstarifadas.g3'),
            'dependency_name: cluster service host' => array('dependency_name', 'msinsurance-service.app-2.svc.cluster.local'),
            'error_type: dotted exception name'     => array('error_type', 'System.Security.Cryptography.X509Certificates.CryptographicException'),
            'route: versioned asset name'           => array('route', '/static/jquery-3.6.0.min.js'),
        );
    }

    public function testUrlPathSegmentsFollowTheSameRules(): void
    {
        self::assertSame('/order/{id}', UrlSanitizer::sanitizePath('/order/reserva_201211'));
        self::assertSame('/order/{id}', UrlSanitizer::sanitizePath('/order/reserva-201211'));
        self::assertSame(
            '/rest/v2/payment/PaymentPaymeeNotifications2',
            UrlSanitizer::sanitizePath('/rest/v2/payment/PaymentPaymeeNotifications2'),
            'a route name with a digit is a name'
        );
    }

    /**
     * `is_bot` stays a strict enum: it is genuinely ternary, so a fourth value is
     * a defect and not a vocabulary this library failed to anticipate.
     */
    public function testIsBotIsStillAClosedEnum(): void
    {
        $redactor = new AttributeRedactor(EnvConfigResolver::resolve());

        self::assertSame('true', $redactor->redactMetricLabels(array('is_bot' => 'true'), array('is_bot'))['is_bot']);
        self::assertSame('other', $redactor->redactMetricLabels(array('is_bot' => 'maybe'), array('is_bot'))['is_bot']);
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
