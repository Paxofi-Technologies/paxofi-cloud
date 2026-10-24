<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Application\Email;

use PaxofiCloud\Application\Email\EmailAddress;
use PaxofiCloud\Application\Email\EmailTemplates;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EmailTemplatesTest extends TestCase
{
    private const string TOKEN = 'abcdefghijklmnopqrstuvwxyz012345ABCDEFGH';

    public function testVerificationLinkUsesTheConfiguredBaseUrlAndCarriesTheTokenInTheFragment(): void
    {
        $message = (new EmailTemplates('https://cloud.paxofi.com/'))->render(new EmailAddress('ada@example.ng'), 'verify-email', ['name' => 'Ada', 'token' => self::TOKEN], 'outbox1');

        $link = 'https://cloud.paxofi.com/verify-email#token=' . self::TOKEN;
        self::assertStringContainsString($link, $message->textBody);
        self::assertStringContainsString('href="' . $link . '"', $message->htmlBody);
        self::assertSame('verify-email', $message->tag);
        self::assertSame('outbox1', $message->idempotencyKey);
        self::assertStringNotContainsString('?token=', $message->textBody, 'Tokens must never travel in the query string');
    }

    /** M-05: a customer-controlled name cannot inject markup or a link. */
    public function testNameIsReducedToPlainLettersAndEscaped(): void
    {
        $message = (new EmailTemplates('https://cloud.paxofi.com'))->render(
            new EmailAddress('ada@example.ng'),
            'account-exists',
            ['name' => '<script>alert(1)</script> Visit https://evil.example "now"'],
            'outbox2',
        );

        foreach ([$message->textBody, $message->htmlBody] as $body) {
            self::assertStringNotContainsString('<script', $body);
            self::assertStringNotContainsString('evil.example', $body);
            self::assertStringNotContainsString('https://evil', $body);
        }
    }

    public function testOverlongOrEmptyNamesFallBackToAGenericGreeting(): void
    {
        $templates = new EmailTemplates('https://cloud.paxofi.com');
        $to = new EmailAddress('ada@example.ng');

        self::assertStringStartsWith('Hi there,', $templates->render($to, 'account-exists', ['name' => str_repeat('a', 60)], 'k')->textBody);
        self::assertStringStartsWith('Hi there,', $templates->render($to, 'account-exists', ['name' => '12345'], 'k')->textBody);
        self::assertStringStartsWith("Hi Ọlá Àdé,", $templates->render($to, 'account-exists', ['name' => 'Ọlá  Àdé'], 'k')->textBody);
    }

    /** @return iterable<string, array{string}> */
    public static function badBaseUrls(): iterable
    {
        yield 'plain http' => ['http://cloud.paxofi.com'];
        yield 'credentials' => ['https://user:pass@cloud.paxofi.com'];
        yield 'query' => ['https://cloud.paxofi.com/?x=1'];
        yield 'not a url' => ['cloud.paxofi.com'];
    }

    #[DataProvider('badBaseUrls')]
    public function testBaseUrlMustBeHttps(string $url): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new EmailTemplates($url);
    }

    public function testLocalDevelopmentMayUseHttpOnLoopback(): void
    {
        $message = (new EmailTemplates('http://127.0.0.1:8080'))->render(new EmailAddress('a@b.ng'), 'verify-email', ['token' => self::TOKEN], 'k');
        self::assertStringContainsString('http://127.0.0.1:8080/verify-email#token=', $message->textBody);
    }

    public function testUnknownTemplateAndMalformedTokenAreRefused(): void
    {
        $templates = new EmailTemplates('https://cloud.paxofi.com');
        $to = new EmailAddress('a@b.ng');

        try {
            $templates->render($to, 'password-reset-v0', [], 'k');
            self::fail('Unknown template rendered');
        } catch (\InvalidArgumentException) {
        }

        $this->expectException(\InvalidArgumentException::class);
        $templates->render($to, 'verify-email', ['token' => 'short"><a href=x>'], 'k');
    }

    public function testSignInAlertsShowDeviceLocationIpAndNigerianTime(): void
    {
        $templates = new EmailTemplates('https://cloud.paxofi.com');
        $parameters = ['name' => 'Ada', 'device' => 'Safari 17 on iOS 17.6 (mobile)', 'location' => 'Ikeja, Lagos, Nigeria', 'ip' => '203.0.113.9', 'time' => '2026-10-18T08:43:00Z'];

        $normal = $templates->render(new EmailAddress('ada@example.ng'), EmailTemplates::SIGN_IN_ALERT, $parameters, 'k1');
        $new = $templates->render(new EmailAddress('ada@example.ng'), EmailTemplates::NEW_DEVICE_ALERT, $parameters, 'k2');

        self::assertSame('You signed in to PaxofiCloud', $normal->subject);
        self::assertSame('New device signed in to your PaxofiCloud account', $new->subject);
        foreach ([$normal->textBody, $normal->htmlBody, $new->textBody, $new->htmlBody] as $body) {
            self::assertStringContainsString('Safari 17 on iOS 17.6 (mobile)', $body);
            self::assertStringContainsString('Ikeja, Lagos, Nigeria (approximate)', $body);
            self::assertStringContainsString('203.0.113.9', $body);
            self::assertStringContainsString('18 Oct 2026, 09:43 WAT', $body);
            self::assertStringContainsString('https://cloud.paxofi.com/security', $body);
        }
        self::assertStringContainsString('have not seen before', $new->textBody);
    }

    public function testAlertParametersThatDoNotMatchTheirPatternShowAsUnknown(): void
    {
        $message = (new EmailTemplates('https://cloud.paxofi.com'))->render(new EmailAddress('ada@example.ng'), EmailTemplates::NEW_DEVICE_ALERT, [
            'name' => 'Ada',
            'device' => '<a href="https://evil.example">Chrome</a>',
            'location' => 'evil.example',
            'ip' => '999.1.1.1',
            'time' => 'yesterday',
        ], 'k');

        self::assertStringNotContainsString('evil.example', $message->textBody . $message->htmlBody, 'T-53');
        self::assertSame(4, substr_count($message->textBody, ': Unknown'));
        self::assertSame(1, preg_match_all('/href="/', $message->htmlBody), 'one link only: the Security page');
    }

    public function testSecurityChangeAndAttemptsAlerts(): void
    {
        $templates = new EmailTemplates('https://cloud.paxofi.com');
        $base = ['name' => 'Ada', 'device' => 'Chrome 129 on Windows (desktop)', 'location' => '', 'ip' => '198.51.100.7', 'time' => '2026-10-18T23:30:00Z'];

        $change = $templates->render(new EmailAddress('ada@example.ng'), EmailTemplates::SECURITY_CHANGE, $base + ['change' => 'mfa_disabled'], 'k1');
        self::assertStringContainsString('Two-step verification was turned off.', $change->textBody);
        self::assertStringContainsString('19 Oct 2026, 00:30 WAT', $change->textBody, 'shown in Nigerian time, across midnight');
        self::assertStringContainsString('Location: Unknown', $change->textBody);
        $city = $templates->render(new EmailAddress('ada@example.ng'), EmailTemplates::SIGN_IN_ALERT, ['location' => "St. John's, Newfoundland, Canada"] + $base, 'k4');
        self::assertStringContainsString("St. John's, Newfoundland, Canada (approximate)", $city->textBody);

        $attempts = $templates->render(new EmailAddress('ada@example.ng'), EmailTemplates::SIGN_IN_ATTEMPTS_ALERT, $base + ['attempts' => '11'], 'k2');
        self::assertStringContainsString('There were 11 unsuccessful attempts', $attempts->textBody);

        $this->expectException(\InvalidArgumentException::class);
        $templates->render(new EmailAddress('ada@example.ng'), EmailTemplates::SECURITY_CHANGE, $base + ['change' => 'password_changed_by_attacker'], 'k3');
    }

    /** §6.17: the staff invitation links to the admin origin, token in the fragment; no admin origin, no invitation. */
    public function testStaffInvitationLinksToTheAdminOriginWithTheTokenInTheFragment(): void
    {
        $templates = new EmailTemplates('https://cloud.paxofi.com', 'PaxofiCloud', 'https://admin.paxofi.com/');
        $message = $templates->render(new EmailAddress('ada@example.ng'), EmailTemplates::STAFF_INVITATION, ['name' => 'Ada', 'token' => self::TOKEN], 'k1');

        self::assertStringContainsString('https://admin.paxofi.com/enrol#token=' . self::TOKEN, $message->textBody);
        self::assertStringContainsString('href="https://admin.paxofi.com/enrol#token=' . self::TOKEN . '"', $message->htmlBody);
        self::assertStringNotContainsString('cloud.paxofi.com', $message->textBody);
        self::assertStringContainsString('choose a password and add an authenticator app', $message->textBody);

        $this->expectException(\InvalidArgumentException::class);
        (new EmailTemplates('https://cloud.paxofi.com'))->render(new EmailAddress('ada@example.ng'), EmailTemplates::STAFF_INVITATION, ['name' => 'Ada', 'token' => self::TOKEN], 'k2');
    }

    public function testAdminUrlMustBeHttpsToo(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new EmailTemplates('https://cloud.paxofi.com', 'PaxofiCloud', 'http://admin.paxofi.com');
    }

    public function testStaffActivatedAlertNamesTheNewMemberSafely(): void
    {
        $templates = new EmailTemplates('https://cloud.paxofi.com', 'PaxofiCloud', 'https://admin.paxofi.com');
        $message = $templates->render(new EmailAddress('sec@example.ng'), EmailTemplates::STAFF_ACTIVATED, ['name' => 'Ada', 'staff_name' => 'Bob <script>Builder</script>'], 'k3');

        self::assertStringContainsString('Bob scriptBuilderscript finished setting up their sign-in', $message->textBody);
        self::assertStringNotContainsString('<script>', $message->htmlBody);
        self::assertStringContainsString('href="https://admin.paxofi.com/staff"', $message->htmlBody);
    }

    /** §6.20: the code, the device signing in, where the code may be typed; no link, code not in the subject. */
    public function testStaffSignInCodeNamesTheDeviceAndTheOnlyPlaceToTypeIt(): void
    {
        $templates = new EmailTemplates('https://cloud.paxofi.com', 'PaxofiCloud', 'https://admin.paxofi.com');
        $message = $templates->render(new EmailAddress('ada@example.ng'), EmailTemplates::STAFF_SIGN_IN_CODE, [
            'name' => 'Ada',
            'code' => '042917',
            'device' => 'Chrome 146 on Windows (desktop)',
            'location' => 'Lagos, Nigeria',
            'ip' => '102.89.1.2',
            'time' => '2026-10-19T08:30:00Z',
        ], 'k4');

        self::assertSame('Your PaxofiCloud admin sign-in code', $message->subject);
        self::assertStringContainsString("\n042917\n", $message->textBody);
        self::assertStringContainsString('>042917</p>', $message->htmlBody);
        self::assertStringContainsString('Device: Chrome 146 on Windows (desktop)', $message->textBody);
        self::assertStringContainsString('Location: Lagos, Nigeria (approximate)', $message->textBody);
        self::assertStringContainsString('IP address: 102.89.1.2', $message->textBody);
        self::assertStringContainsString('Time: 19 Oct 2026, 09:30 WAT', $message->textBody);
        self::assertStringContainsString('Only type this code on admin.paxofi.com', $message->textBody);
        self::assertStringNotContainsString('href=', $message->htmlBody);

        $this->expectException(\InvalidArgumentException::class);
        $templates->render(new EmailAddress('ada@example.ng'), EmailTemplates::STAFF_SIGN_IN_CODE, ['name' => 'Ada', 'code' => '12345<'], 'k5');
    }

    /** DR-8 / DR-9: price sync alerts name the provider, a fixed problem and the time, and link only to the admin console. */
    public function testPriceSyncProblemAlert(): void
    {
        $templates = new EmailTemplates('https://cloud.paxofi.com', 'PaxofiCloud', 'https://admin.paxofi.com');

        $message = $templates->render(new EmailAddress('ada@example.ng'), EmailTemplates::PRICE_SYNC_PROBLEM, [
            'name' => 'Ada', 'provider' => 'domainnameapi', 'problem' => 'credential_refused', 'time' => '2026-10-24T01:30:00Z',
        ], 'k3');

        self::assertSame('Price sync needs attention: DomainNameAPI (domain registrar)', $message->subject);
        self::assertStringContainsString('refused our API credential', $message->textBody);
        self::assertStringContainsString('24 Oct 2026, 02:30 WAT', $message->textBody);
        self::assertStringContainsString('https://admin.paxofi.com/providers', $message->textBody);

        $this->expectException(\InvalidArgumentException::class);
        $templates->render(new EmailAddress('ada@example.ng'), EmailTemplates::PRICE_SYNC_PROBLEM, ['provider' => 'domainnameapi', 'problem' => '<script>'], 'k4');
    }
}
