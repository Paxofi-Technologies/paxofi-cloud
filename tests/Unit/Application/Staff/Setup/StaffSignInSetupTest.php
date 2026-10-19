<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Application\Staff\Setup;

use PaxofiCloud\Application\Audit\AuditEventType;
use PaxofiCloud\Application\Email\EmailTemplates;
use PaxofiCloud\Application\Identity\Mfa\Totp;
use PaxofiCloud\Application\Staff\Setup\InvalidStaffSetup;
use PaxofiCloud\Application\Staff\Setup\StaffSetupRefused;
use PaxofiCloud\Application\Staff\Setup\StaffSignInSetup;
use PaxofiCloud\Domain\Staff\StaffRole;
use PaxofiCloud\Tests\Support\AuditFixtures;
use PaxofiCloud\Tests\Support\ScriptedBreachCheck;
use PaxofiCloud\Tests\Support\SoftwareAuthenticator;
use PaxofiCloud\Tests\Support\StaffPasskeyFixtures;
use PHPUnit\Framework\TestCase;

/** Setting up password + authenticator sign-in (D-11 as amended, §6.20). */
final class StaffSignInSetupTest extends TestCase
{
    private StaffPasskeyFixtures $f;
    private string $staffId;
    private string $token;

    protected function setUp(): void
    {
        $this->f = new StaffPasskeyFixtures();
        $this->staffId = $this->f->invitations->bootstrap('ada@example.test', 'Ada Lovelace', AuditFixtures::client());
        $this->token = $this->f->lastToken();
        $this->f->audit->events = [];
    }

    public function testTheInvitationActivatesTheMemberWithPasswordAuthenticatorAndRecoveryCodes(): void
    {
        $started = $this->f->setup->startWithInvitation($this->token, AuditFixtures::client());
        self::assertSame('Ada Lovelace', $started['full_name']);
        self::assertSame('ada@example.test', $started['email']);
        self::assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $started['secret']);
        self::assertStringStartsWith('otpauth://totp/PaxofiCloud%20Admin:ada%40example.test?secret=' . $started['secret'], $started['uri']);
        self::assertSame('invited', $this->f->directory->members[$this->staffId]);

        $codes = $this->finish();

        self::assertCount(10, $codes);
        self::assertSame('active', $this->f->directory->members[$this->staffId]);
        self::assertTrue($this->f->store->invitations[hash('sha256', $this->token)]['used']);
        self::assertSame('$argon2id$test$' . hash('sha256', StaffPasskeyFixtures::PASSWORD), $this->f->credentials->passwords[$this->staffId]);
        self::assertTrue($this->f->credentials->totp[$this->staffId]['confirmed']);
        self::assertCount(10, $this->f->credentials->recoveryCodes);
        // Only keyed hashes are stored, never a code.
        foreach ($codes as $code) {
            self::assertArrayNotHasKey($code, $this->f->credentials->recoveryCodes);
        }
        self::assertSame([AuditEventType::STAFF_SIGN_IN_SET_UP, AuditEventType::STAFF_ACTIVATED], $this->f->audit->types());

        // The used invitation cannot start or finish again.
        $this->expectRefused(StaffSetupRefused::INVITATION, fn () => $this->f->setup->startWithInvitation($this->token, AuditFixtures::client()));
    }

    /** The code that confirmed the setup cannot also be used to sign in (T-13). */
    public function testTheSetupCodeIsSpent(): void
    {
        $this->f->setup->startWithInvitation($this->token, AuditFixtures::client());
        $code = Totp::code($this->f->scannedSecret($this->staffId), Totp::step($this->f->clock->now()));
        $this->f->setup->finishWithInvitation($this->token, StaffPasskeyFixtures::PASSWORD, $code, AuditFixtures::client());

        $step = $this->f->codes->password('ada@example.test', StaffPasskeyFixtures::PASSWORD, AuditFixtures::client());
        $this->expectException(\PaxofiCloud\Application\Staff\SignIn\StaffSignInRefused::class);
        $this->f->codes->authenticatorCode($step->ticket, $code, AuditFixtures::client());
    }

    public function testStartingAgainReplacesAnUnscannedSecret(): void
    {
        $first = $this->f->setup->startWithInvitation($this->token, AuditFixtures::client());
        $second = $this->f->setup->startWithInvitation($this->token, AuditFixtures::client());
        self::assertNotSame($first['secret'], $second['secret']);
        $this->finish();
    }

    /** @return iterable<string, array{string, string}> */
    public static function badPasswords(): iterable
    {
        yield 'too short' => [str_repeat('a', StaffSignInSetup::PASSWORD_MIN - 1), 'too_short'];
        yield 'too long' => [str_repeat('a', StaffSignInSetup::PASSWORD_MAX + 1), 'too_long'];
        yield 'control character' => ["correct horse\x00battery staple", 'invalid_characters'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badPasswords')]
    public function testWeakPasswordsAreRefusedAndNothingChanges(string $password, string $error): void
    {
        $this->f->setup->startWithInvitation($this->token, AuditFixtures::client());
        $this->expectInvalid(['password' => $error], fn () => $this->finish($password));
        self::assertSame('invited', $this->f->directory->members[$this->staffId]);
        self::assertSame([], $this->f->credentials->passwords);
    }

    public function testABreachedPasswordIsRefused(): void
    {
        $f = new StaffPasskeyFixtures(new ScriptedBreachCheck(true));
        $staffId = $f->invitations->bootstrap('ada@example.test', 'Ada Lovelace', AuditFixtures::client());
        $token = $f->lastToken();
        $f->setup->startWithInvitation($token, AuditFixtures::client());
        $code = Totp::code($f->scannedSecret($staffId), Totp::step($f->clock->now()));

        $this->expectInvalid(['password' => 'breached'], fn () => $f->setup->finishWithInvitation($token, StaffPasskeyFixtures::PASSWORD, $code, AuditFixtures::client()));
    }

    public function testAWrongCodeOrNoScannedSecretIsRefused(): void
    {
        $this->expectInvalid(['code' => 'invalid'], fn () => $this->f->setup->finishWithInvitation($this->token, StaffPasskeyFixtures::PASSWORD, '123456', AuditFixtures::client()));

        $this->f->setup->startWithInvitation($this->token, AuditFixtures::client());
        $this->expectInvalid(['code' => 'invalid'], fn () => $this->f->setup->finishWithInvitation($this->token, StaffPasskeyFixtures::PASSWORD, '000000', AuditFixtures::client()));
        $this->expectInvalid(['password' => 'too_short', 'code' => 'invalid'], fn () => $this->f->setup->finishWithInvitation($this->token, 'short', 'abc', AuditFixtures::client()));
        self::assertSame(0, $this->f->hasher->hashes);
    }

    public function testUnknownMalformedAndExpiredInvitationsAreRefused(): void
    {
        $this->expectRefused(StaffSetupRefused::INVITATION, fn () => $this->f->setup->startWithInvitation(str_repeat('A', 43), AuditFixtures::client()));
        $this->expectRefused(StaffSetupRefused::INVITATION, fn () => $this->f->setup->startWithInvitation('short', AuditFixtures::client()));

        $this->f->setup->startWithInvitation($this->token, AuditFixtures::client());
        $code = Totp::code($this->f->scannedSecret($this->staffId), Totp::step($this->f->clock->now()));
        $this->f->clock->advance('+24 hours');
        $this->expectRefused(StaffSetupRefused::INVITATION, fn () => $this->f->setup->finishWithInvitation($this->token, StaffPasskeyFixtures::PASSWORD, $code, AuditFixtures::client()));
        self::assertSame('invited', $this->f->directory->members[$this->staffId]);
    }

    /** §6.18: other active staff managers are told when someone activates. */
    public function testActivationAlertsTheOtherStaffManagers(): void
    {
        $this->finish();
        $this->f->invitations->invite($this->staffId, 'bob@example.test', 'Bob Builder', StaffRole::SecurityAdmin, AuditFixtures::client());
        $bobToken = $this->f->lastToken();
        $before = count($this->f->outbox->queued);

        $this->f->activate($bobToken);

        $alerts = array_values(array_filter(array_slice($this->f->outbox->queued, $before), static fn (array $e): bool => $e['template'] === EmailTemplates::STAFF_ACTIVATED));
        self::assertCount(1, $alerts);
        self::assertSame('ada@example.test', $alerts[0]['to']);
        self::assertSame('Bob Builder', $alerts[0]['parameters']['staff_name']);
    }

    /** Members who enrolled with passkeys before §6.20 set up codes once, after a passkey sign-in. */
    public function testAPasskeyOnlyMemberSetsUpCodesOnce(): void
    {
        $this->f->directory->members[$this->staffId] = 'active';

        $started = $this->f->setup->startForMember($this->staffId, AuditFixtures::client());
        self::assertSame('ada@example.test', $started['email']);
        $code = Totp::code($this->f->scannedSecret($this->staffId), Totp::step($this->f->clock->now()));
        self::assertCount(10, $this->f->setup->finishForMember($this->staffId, StaffPasskeyFixtures::PASSWORD, $code, AuditFixtures::client()));
        self::assertSame(['subject' => $this->staffId, 'via' => 'session'], $this->f->audit->events[0]->details);

        // Never a second time: a stolen session cannot replace the password or authenticator.
        $this->expectRefused(StaffSetupRefused::NOT_ALLOWED, fn () => $this->f->setup->startForMember($this->staffId, AuditFixtures::client()));
        $this->expectRefused(StaffSetupRefused::NOT_ALLOWED, fn () => $this->f->setup->finishForMember($this->staffId, 'another long password here', $code, AuditFixtures::client()));
        self::assertSame('$argon2id$test$' . hash('sha256', StaffPasskeyFixtures::PASSWORD), $this->f->credentials->passwords[$this->staffId]);
    }

    public function testInvitedOrDisabledMembersCannotUseTheSessionPath(): void
    {
        $this->expectRefused(StaffSetupRefused::NOT_ALLOWED, fn () => $this->f->setup->startForMember($this->staffId, AuditFixtures::client()));
        $this->f->directory->members[$this->staffId] = 'disabled';
        $this->expectRefused(StaffSetupRefused::NOT_ALLOWED, fn () => $this->f->setup->startForMember($this->staffId, AuditFixtures::client()));
    }

    public function testSetupIsThrottledPerIp(): void
    {
        for ($i = 0; $i < StaffSignInSetup::IP_LIMIT_PER_HOUR; $i++) {
            $this->f->setup->startWithInvitation($this->token, AuditFixtures::client());
        }
        try {
            $this->f->setup->startWithInvitation($this->token, AuditFixtures::client());
            self::fail('Expected throttling.');
        } catch (StaffSetupRefused $e) {
            self::assertSame(StaffSetupRefused::THROTTLED, $e->reason);
            self::assertGreaterThan(0, $e->retryAfter);
        }
    }

    public function testPasskeysAddedAfterActivationStillWork(): void
    {
        $this->finish();
        $key = new SoftwareAuthenticator(StaffPasskeyFixtures::ORIGIN);
        $this->f->scannedSecret($this->staffId);
        $this->f->addPasskey($this->staffId, $key);

        self::assertSame($this->staffId, $this->f->signInWith($key));
    }

    /** @return list<string> */
    private function finish(string $password = StaffPasskeyFixtures::PASSWORD): array
    {
        if (!isset($this->f->credentials->totp[$this->staffId])) {
            $this->f->setup->startWithInvitation($this->token, AuditFixtures::client());
        }
        $code = Totp::code($this->f->scannedSecret($this->staffId), Totp::step($this->f->clock->now()));

        return $this->f->setup->finishWithInvitation($this->token, $password, $code, AuditFixtures::client());
    }

    /** @param array<string, string> $errors */
    private function expectInvalid(array $errors, \Closure $action): void
    {
        try {
            $action();
            self::fail('Expected field errors.');
        } catch (InvalidStaffSetup $e) {
            self::assertSame($errors, $e->errors);
        }
    }

    private function expectRefused(string $reason, \Closure $action): void
    {
        try {
            $action();
            self::fail('Expected a refusal: ' . $reason);
        } catch (StaffSetupRefused $e) {
            self::assertSame($reason, $e->reason);
        }
        $last = $this->f->audit->events[count($this->f->audit->events) - 1] ?? null;
        self::assertNotNull($last);
        self::assertSame(AuditEventType::STAFF_SIGN_IN_SETUP_REFUSED, $last->type);
    }
}
