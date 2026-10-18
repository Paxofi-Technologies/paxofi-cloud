<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Application\Staff\Passkeys;

use PaxofiCloud\Application\Audit\AuditEventType;
use PaxofiCloud\Application\Staff\Passkeys\PasskeyRejected;
use PaxofiCloud\Application\Staff\Passkeys\StaffEnrolment;
use PaxofiCloud\Tests\Support\AuditFixtures;
use PaxofiCloud\Tests\Support\SoftwareAuthenticator;
use PaxofiCloud\Tests\Support\StaffPasskeyFixtures;
use PHPUnit\Framework\TestCase;

/** Passkey enrolment with an invitation (threat model D-11, §6.17). */
final class StaffEnrolmentTest extends TestCase
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

    /** D-11: the member becomes active with the second passkey, and the invitation is then used up. */
    public function testTheSecondPasskeyActivatesTheMemberAndUsesUpTheInvitation(): void
    {
        self::assertSame(['passkeys' => 1, 'active' => false], $this->f->enrol($this->token, new SoftwareAuthenticator(StaffPasskeyFixtures::ORIGIN), 'YubiKey'));
        self::assertSame('invited', $this->f->directory->members[$this->staffId]);

        self::assertSame(['passkeys' => 2, 'active' => true], $this->f->enrol($this->token, new SoftwareAuthenticator(StaffPasskeyFixtures::ORIGIN, -8)));
        self::assertSame('active', $this->f->directory->members[$this->staffId]);
        self::assertTrue($this->f->store->invitations[hash('sha256', $this->token)]['used']);

        $labels = array_column($this->f->store->passkeys, 'label');
        self::assertSame(['YubiKey', 'Passkey 2'], $labels);
        self::assertSame(
            [AuditEventType::STAFF_PASSKEY_REGISTERED, AuditEventType::STAFF_PASSKEY_REGISTERED, AuditEventType::STAFF_ACTIVATED],
            $this->f->audit->types(),
        );

        // The used invitation cannot start another ceremony.
        $this->expectRejected(PasskeyRejected::INVITATION, fn () => $this->f->enrolment->begin($this->token, AuditFixtures::client()));
    }

    public function testOptionsExcludePasskeysAlreadyRegistered(): void
    {
        $first = new SoftwareAuthenticator(StaffPasskeyFixtures::ORIGIN);
        $this->f->enrol($this->token, $first);

        $options = $this->f->enrolment->begin($this->token, AuditFixtures::client())['options'];
        $excluded = $options['excludeCredentials'];
        self::assertIsArray($excluded);
        self::assertCount(1, $excluded);
    }

    public function testTheSameAuthenticatorCannotCountTwice(): void
    {
        $authenticator = new SoftwareAuthenticator(StaffPasskeyFixtures::ORIGIN);
        $this->f->enrol($this->token, $authenticator);

        $this->expectRejected(PasskeyRejected::INVALID, fn () => $this->f->enrol($this->token, $authenticator));
        self::assertSame('invited', $this->f->directory->members[$this->staffId]);
    }

    public function testUnknownMalformedAndExpiredInvitationsAreRefused(): void
    {
        $this->expectRejected(PasskeyRejected::INVITATION, fn () => $this->f->enrolment->begin(str_repeat('A', 43), AuditFixtures::client()));
        $this->expectRejected(PasskeyRejected::INVITATION, fn () => $this->f->enrolment->begin('short', AuditFixtures::client()));

        $this->f->clock->advance('+24 hours');
        $this->expectRejected(PasskeyRejected::INVITATION, fn () => $this->f->enrolment->begin($this->token, AuditFixtures::client()));
    }

    public function testAnInvitationThatExpiresMidCeremonyRegistersNothing(): void
    {
        $begun = $this->f->enrolment->begin($this->token, AuditFixtures::client());
        $this->f->clock->advance('+24 hours');

        $authenticator = new SoftwareAuthenticator(StaffPasskeyFixtures::ORIGIN);
        $this->expectRejected(PasskeyRejected::INVITATION, fn () => $this->f->enrolment->finish($this->token, $begun['ceremony_id'], $authenticator->register($begun['options']), '', AuditFixtures::client()));
        self::assertSame([], $this->f->store->passkeys);
    }

    public function testACeremonyFinishesOnceAndOnlyWithItsOwnInvitation(): void
    {
        $begun = $this->f->enrolment->begin($this->token, AuditFixtures::client());
        $json = (new SoftwareAuthenticator(StaffPasskeyFixtures::ORIGIN))->register($begun['options']);

        // Another invitation cannot finish this ceremony; the attempt also uses the ceremony up.
        $this->f->invitations->bootstrap('bob@example.test', 'Bob', AuditFixtures::client());
        $other = $this->f->lastToken();
        $this->expectRejected(PasskeyRejected::CEREMONY, fn () => $this->f->enrolment->finish($other, $begun['ceremony_id'], $json, '', AuditFixtures::client()));
        $this->expectRejected(PasskeyRejected::CEREMONY, fn () => $this->f->enrolment->finish($this->token, $begun['ceremony_id'], $json, '', AuditFixtures::client()));

        $again = $this->f->enrolment->begin($this->token, AuditFixtures::client());
        $this->f->enrolment->finish($this->token, $again['ceremony_id'], (new SoftwareAuthenticator(StaffPasskeyFixtures::ORIGIN))->register($again['options']), '', AuditFixtures::client());
        $this->expectRejected(PasskeyRejected::CEREMONY, fn () => $this->f->enrolment->finish($this->token, $again['ceremony_id'], $json, '', AuditFixtures::client()));
        self::assertCount(1, $this->f->store->passkeys);
    }

    public function testASignInCeremonyCannotBeUsedToRegister(): void
    {
        $signIn = $this->f->signIn->begin(AuditFixtures::client());
        $json = (new SoftwareAuthenticator(StaffPasskeyFixtures::ORIGIN))->register($this->f->enrolment->begin($this->token, AuditFixtures::client())['options']);

        $this->expectRejected(PasskeyRejected::CEREMONY, fn () => $this->f->enrolment->finish($this->token, $signIn['ceremony_id'], $json, '', AuditFixtures::client()));
    }

    public function testAPhishedRegistrationFromAnotherOriginIsRejected(): void
    {
        $this->expectRejected(PasskeyRejected::INVALID, fn () => $this->f->enrol($this->token, new SoftwareAuthenticator('https://admin-example.test')));
        self::assertSame([], $this->f->store->passkeys);
    }

    public function testLabelsAreCleaned(): void
    {
        $this->f->enrol($this->token, new SoftwareAuthenticator(StaffPasskeyFixtures::ORIGIN), '<b>bold</b>');
        self::assertSame(['Passkey 1'], array_column($this->f->store->passkeys, 'label'));
    }

    public function testCeremoniesAreThrottledPerIp(): void
    {
        for ($i = 0; $i < StaffEnrolment::IP_LIMIT_PER_HOUR; $i++) {
            $this->f->enrolment->begin($this->token, AuditFixtures::client());
        }
        $this->expectRejected(PasskeyRejected::THROTTLED, fn () => $this->f->enrolment->begin($this->token, AuditFixtures::client()), false);
    }

    /** @param callable(): mixed $action */
    private function expectRejected(string $reason, callable $action, bool $audited = true): void
    {
        $before = count($this->f->audit->events);
        try {
            $action();
            self::fail('Expected rejection: ' . $reason);
        } catch (PasskeyRejected $e) {
            self::assertSame($reason, $e->reason);
        }
        if ($audited) {
            $last = $this->f->audit->events[count($this->f->audit->events) - 1];
            self::assertSame(AuditEventType::STAFF_PASSKEY_REJECTED, $last->type);
            self::assertSame($reason, $last->details['reason'] ?? null);
        } else {
            self::assertCount($before, $this->f->audit->events);
        }
    }
}
