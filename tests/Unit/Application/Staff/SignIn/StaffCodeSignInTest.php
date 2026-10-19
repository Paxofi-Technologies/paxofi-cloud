<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Application\Staff\SignIn;

use PaxofiCloud\Application\Audit\AuditEventType;
use PaxofiCloud\Application\Email\EmailTemplates;
use PaxofiCloud\Application\Identity\SignIn\SignIn;
use PaxofiCloud\Application\Staff\SignIn\StaffCodeSignIn;
use PaxofiCloud\Application\Staff\SignIn\StaffSignInRefused;
use PaxofiCloud\Application\Staff\SignIn\StaffSignInStep;
use PaxofiCloud\Domain\Staff\StaffRole;
use PaxofiCloud\Tests\Support\AuditFixtures;
use PaxofiCloud\Tests\Support\StaffPasskeyFixtures;
use PHPUnit\Framework\TestCase;

/** Staff sign-in with password, authenticator code and emailed code (§6.20, T-29, R-5). */
final class StaffCodeSignInTest extends TestCase
{
    private const string EMAIL = 'ada@example.test';

    private StaffPasskeyFixtures $f;
    private string $staffId;
    /** @var list<string> */
    private array $recoveryCodes;

    protected function setUp(): void
    {
        $this->f = new StaffPasskeyFixtures();
        $this->staffId = $this->f->invitations->bootstrap(self::EMAIL, 'Ada Lovelace', AuditFixtures::client());
        $this->recoveryCodes = $this->f->activate($this->f->lastToken());
        $this->f->audit->events = [];
        $this->f->outbox->queued = [];
    }

    public function testPasswordThenAuthenticatorThenEmailCodeSignsIn(): void
    {
        $step = $this->f->codes->password(self::EMAIL, StaffPasskeyFixtures::PASSWORD, AuditFixtures::client());
        self::assertSame(StaffSignInStep::TOTP, $step->next);
        self::assertSame(StaffCodeSignIn::TICKET_SECONDS, $step->expiresIn);
        self::assertSame([], $this->f->outbox->queued, 'no email before the authenticator code');

        $step = $this->f->codes->authenticatorCode($step->ticket, $this->f->totpCode($this->staffId), AuditFixtures::client());
        self::assertSame(StaffSignInStep::EMAIL, $step->next);
        self::assertCount(1, $this->f->outbox->queued);
        $email = $this->f->outbox->queued[0];
        self::assertSame(self::EMAIL, $email['to']);
        self::assertSame(EmailTemplates::STAFF_SIGN_IN_CODE, $email['template']);
        self::assertMatchesRegularExpression('/^\d{6}$/', $email['parameters']['code']);
        foreach (['device', 'location', 'ip', 'time'] as $key) {
            self::assertArrayHasKey($key, $email['parameters']);
        }

        self::assertSame($this->staffId, $this->f->codes->emailCode($step->ticket, $email['parameters']['code'], AuditFixtures::client()));
        self::assertSame([
            AuditEventType::STAFF_PASSWORD_ACCEPTED,
            AuditEventType::STAFF_CODE_ACCEPTED,
            AuditEventType::STAFF_EMAIL_CODE_SENT,
            AuditEventType::STAFF_SIGNED_IN,
        ], $this->f->audit->types());
        self::assertSame(['subject' => $this->staffId, 'method' => 'password_codes'], $this->f->audit->events[3]->details);
        // The ticket is stored only under its hash, and is gone once used.
        self::assertSame([], $this->f->tickets->states);
    }

    public function testARecoveryCodeReplacesTheAuthenticatorCodeOnce(): void
    {
        $step = $this->f->codes->password(self::EMAIL, StaffPasskeyFixtures::PASSWORD, AuditFixtures::client());
        $step = $this->f->codes->authenticatorCode($step->ticket, strtolower($this->recoveryCodes[0]), AuditFixtures::client());
        self::assertSame(StaffSignInStep::EMAIL, $step->next);
        self::assertSame(['subject' => $this->staffId, 'method' => 'recovery_code'], $this->f->audit->events[1]->details);

        $step = $this->f->codes->password(self::EMAIL, StaffPasskeyFixtures::PASSWORD, AuditFixtures::client());
        $this->expectRefused(StaffSignInRefused::CODE_INVALID, fn () => $this->f->codes->authenticatorCode($step->ticket, $this->recoveryCodes[0], AuditFixtures::client()));
    }

    /** D-7: every failure is the same and costs one password verification. */
    public function testEveryPasswordFailureLooksTheSame(): void
    {
        $bob = $this->f->invitations->invite($this->staffId, 'bob@example.test', 'Bob', StaffRole::Support, AuditFixtures::client());
        $this->f->hasher->verified = [];

        $cases = [
            ['nobody@example.test', StaffPasskeyFixtures::PASSWORD, 'unknown_account'],
            ['not an email', StaffPasskeyFixtures::PASSWORD, 'unknown_account'],
            [self::EMAIL, 'wrong password, long enough', 'wrong_password'],
            ['bob@example.test', StaffPasskeyFixtures::PASSWORD, 'no_password'],
        ];
        foreach ($cases as [$email, $password, $reason]) {
            $this->expectRefused(StaffSignInRefused::FAILED, fn () => $this->f->codes->password($email, $password, AuditFixtures::client()));
            $last = $this->f->audit->events[count($this->f->audit->events) - 1];
            self::assertSame(AuditEventType::STAFF_SIGN_IN_FAILED, $last->type);
            self::assertSame($reason, $last->details['reason']);
        }
        self::assertCount(4, $this->f->hasher->verified);
        self::assertSame(SignIn::DUMMY_HASH, $this->f->hasher->verified[0]);
        self::assertSame(SignIn::DUMMY_HASH, $this->f->hasher->verified[3], 'a member without a password still costs one verification');
        self::assertSame($bob, $this->f->audit->events[count($this->f->audit->events) - 1]->details['subject']);
        self::assertSame([], $this->f->tickets->states);
    }

    public function testADisabledMemberIsRefusedAfterTheRightPasswordAndAtTheLastStep(): void
    {
        $this->f->directory->members[$this->staffId] = 'disabled';
        $this->expectRefused(StaffSignInRefused::FAILED, fn () => $this->f->codes->password(self::EMAIL, StaffPasskeyFixtures::PASSWORD, AuditFixtures::client()));

        $this->f->directory->members[$this->staffId] = 'active';
        $step = $this->f->codes->password(self::EMAIL, StaffPasskeyFixtures::PASSWORD, AuditFixtures::client());
        $step = $this->f->codes->authenticatorCode($step->ticket, $this->f->totpCode($this->staffId), AuditFixtures::client());
        $this->f->directory->members[$this->staffId] = 'disabled';
        $this->expectRefused(StaffSignInRefused::EXPIRED, fn () => $this->f->codes->emailCode($step->ticket, $this->f->lastEmailCode(), AuditFixtures::client()));
    }

    /** §6.20: each step takes the ticket; a wrong code hands over a new one, the old one is dead. */
    public function testTicketsWorkOnceAndRotateOnAWrongCode(): void
    {
        $first = $this->f->codes->password(self::EMAIL, StaffPasskeyFixtures::PASSWORD, AuditFixtures::client());
        $wrong = $this->expectRefused(StaffSignInRefused::CODE_INVALID, fn () => $this->f->codes->authenticatorCode($first->ticket, '000000', AuditFixtures::client()));
        self::assertNotNull($wrong->ticket);
        self::assertNotSame($first->ticket, $wrong->ticket);

        $this->expectRefused(StaffSignInRefused::EXPIRED, fn () => $this->f->codes->authenticatorCode($first->ticket, $this->f->totpCode($this->staffId), AuditFixtures::client()));
        $step = $this->f->codes->authenticatorCode($wrong->ticket, $this->f->totpCode($this->staffId), AuditFixtures::client());

        // A ticket for the email step cannot be replayed at the authenticator step, or the other way round.
        $this->expectRefused(StaffSignInRefused::EXPIRED, fn () => $this->f->codes->authenticatorCode($step->ticket, $this->f->totpCode($this->staffId), AuditFixtures::client()));
        $this->expectRefused(StaffSignInRefused::EXPIRED, fn () => $this->f->codes->emailCode(null, '123456', AuditFixtures::client()));
        $this->expectRefused(StaffSignInRefused::EXPIRED, fn () => $this->f->codes->emailCode('../../etc', '123456', AuditFixtures::client()));
    }

    public function testFiveWrongCodesEndTheTicket(): void
    {
        $ticket = $this->f->codes->password(self::EMAIL, StaffPasskeyFixtures::PASSWORD, AuditFixtures::client())->ticket;
        for ($i = 1; $i < StaffCodeSignIn::MAX_WRONG_CODES_PER_TICKET; $i++) {
            $ticket = (string) $this->expectRefused(StaffSignInRefused::CODE_INVALID, fn () => $this->f->codes->authenticatorCode($ticket, '000000', AuditFixtures::client()))->ticket;
        }
        $this->expectRefused(StaffSignInRefused::EXPIRED, fn () => $this->f->codes->authenticatorCode($ticket, '000000', AuditFixtures::client()));
        self::assertSame([], $this->f->tickets->states);
    }

    public function testWrongEmailCodesCountAgainstTheSameTicketLimit(): void
    {
        $ticket = $this->f->codes->password(self::EMAIL, StaffPasskeyFixtures::PASSWORD, AuditFixtures::client())->ticket;
        $ticket = (string) $this->expectRefused(StaffSignInRefused::CODE_INVALID, fn () => $this->f->codes->authenticatorCode($ticket, '000000', AuditFixtures::client()))->ticket;
        $ticket = $this->f->codes->authenticatorCode($ticket, $this->f->totpCode($this->staffId), AuditFixtures::client())->ticket;
        $code = $this->f->lastEmailCode();
        $other = $code === '999999' ? '999998' : '999999';
        for ($i = 2; $i < StaffCodeSignIn::MAX_WRONG_CODES_PER_TICKET; $i++) {
            $ticket = (string) $this->expectRefused(StaffSignInRefused::CODE_INVALID, fn () => $this->f->codes->emailCode($ticket, $other, AuditFixtures::client()))->ticket;
        }
        // The fifth wrong code overall ends it, even though the right code is known.
        $this->expectRefused(StaffSignInRefused::EXPIRED, fn () => $this->f->codes->emailCode($ticket, $other, AuditFixtures::client()));
        $this->expectRefused(StaffSignInRefused::EXPIRED, fn () => $this->f->codes->emailCode($ticket, $code, AuditFixtures::client()));
    }

    public function testTheWholeSignInExpiresAfterTenMinutes(): void
    {
        $step = $this->f->codes->password(self::EMAIL, StaffPasskeyFixtures::PASSWORD, AuditFixtures::client());
        $step = $this->f->codes->authenticatorCode($step->ticket, $this->f->totpCode($this->staffId), AuditFixtures::client());
        self::assertSame(StaffCodeSignIn::TICKET_SECONDS - 30, $step->expiresIn, 'the email step keeps the original expiry');
        $this->f->clock->advance('+' . (StaffCodeSignIn::TICKET_SECONDS - 30) . ' seconds');

        $this->expectRefused(StaffSignInRefused::EXPIRED, fn () => $this->f->codes->emailCode($step->ticket, $this->f->lastEmailCode(), AuditFixtures::client()));
    }

    public function testANewEmailCodeReplacesTheOldOneAndIsLimited(): void
    {
        $step = $this->f->codes->password(self::EMAIL, StaffPasskeyFixtures::PASSWORD, AuditFixtures::client());
        $step = $this->f->codes->authenticatorCode($step->ticket, $this->f->totpCode($this->staffId), AuditFixtures::client());
        $old = $this->f->lastEmailCode();

        $soon = $this->expectRefused(StaffSignInRefused::THROTTLED, fn () => $this->f->codes->resendEmailCode($step->ticket, AuditFixtures::client()));
        self::assertSame(StaffCodeSignIn::EMAIL_RESEND_SECONDS, $soon->retryAfter);
        $ticket = (string) $soon->ticket;

        for ($i = 0; $i < StaffCodeSignIn::MAX_EMAIL_RESENDS; $i++) {
            $this->f->clock->advance('+' . StaffCodeSignIn::EMAIL_RESEND_SECONDS . ' seconds');
            $ticket = $this->f->codes->resendEmailCode($ticket, AuditFixtures::client())->ticket;
        }
        self::assertCount(1 + StaffCodeSignIn::MAX_EMAIL_RESENDS, $this->f->outbox->queued);
        $this->f->clock->advance('+' . StaffCodeSignIn::EMAIL_RESEND_SECONDS . ' seconds');
        $ticket = (string) $this->expectRefused(StaffSignInRefused::THROTTLED, fn () => $this->f->codes->resendEmailCode($ticket, AuditFixtures::client()))->ticket;

        $new = $this->f->lastEmailCode();
        if ($old !== $new) {
            $ticket = (string) $this->expectRefused(StaffSignInRefused::CODE_INVALID, fn () => $this->f->codes->emailCode($ticket, $old, AuditFixtures::client()))->ticket;
        }
        self::assertSame($this->staffId, $this->f->codes->emailCode($ticket, $new, AuditFixtures::client()));
    }

    public function testPasswordAttemptsAreLimitedPerAddressBeforeAnyHash(): void
    {
        for ($i = 0; $i < StaffCodeSignIn::ADDRESS_LIMIT; $i++) {
            $this->expectRefused(StaffSignInRefused::FAILED, fn () => $this->f->codes->password(self::EMAIL, 'wrong password, long enough', AuditFixtures::client()));
        }
        $verified = count($this->f->hasher->verified);
        $refused = $this->expectRefused(StaffSignInRefused::THROTTLED, fn () => $this->f->codes->password(self::EMAIL, StaffPasskeyFixtures::PASSWORD, AuditFixtures::client()));
        self::assertGreaterThan(0, $refused->retryAfter);
        self::assertCount($verified, $this->f->hasher->verified);
    }

    /** §6.20: many tickets (each with the right password) cannot add up to many code guesses. */
    public function testCodeAttemptsAreLimitedPerMemberAcrossTickets(): void
    {
        $locked = null;
        for ($i = 0; $i <= StaffCodeSignIn::MEMBER_CODE_LIMIT && $locked === null; $i++) {
            $this->f->limiter->hits = array_filter($this->f->limiter->hits, static fn (string $k): bool => str_starts_with($k, 'staff:codes:'), ARRAY_FILTER_USE_KEY);
            $ticket = $this->f->codes->password(self::EMAIL, StaffPasskeyFixtures::PASSWORD, AuditFixtures::client())->ticket;
            try {
                $this->f->codes->authenticatorCode($ticket, '000000', AuditFixtures::client());
            } catch (StaffSignInRefused $e) {
                $locked = $e->reason === StaffSignInRefused::THROTTLED ? $i : null;
            }
        }
        self::assertSame(StaffCodeSignIn::MEMBER_CODE_LIMIT, $locked);
        $last = $this->f->audit->events[count($this->f->audit->events) - 1];
        self::assertSame('member_locked', $last->details['reason']);
    }

    public function testNoMfaKeysMeansUnavailable(): void
    {
        $codes = new StaffCodeSignIn($this->f->credentials, $this->f->hasher, new class implements \PaxofiCloud\Application\Contracts\PasswordRehashPolicy {
            public function needsRehash(string $hash): bool
            {
                return false;
            }
        }, null, $this->f->tickets, $this->f->limiter, $this->f->outbox, $this->f->clock, AuditFixtures::trail($this->f->audit), $this->f->logger);

        $this->expectRefused(StaffSignInRefused::UNAVAILABLE, fn () => $codes->password(self::EMAIL, StaffPasskeyFixtures::PASSWORD, AuditFixtures::client()));
    }

    private function expectRefused(string $reason, \Closure $action): StaffSignInRefused
    {
        try {
            $action();
        } catch (StaffSignInRefused $e) {
            self::assertSame($reason, $e->reason);

            return $e;
        }
        self::fail('Expected a refusal: ' . $reason);
    }
}
