<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Application\Staff\Passkeys;

use PaxofiCloud\Application\Audit\AuditEventType;
use PaxofiCloud\Application\Staff\Passkeys\PasskeyRejected;
use PaxofiCloud\Application\Staff\Passkeys\StaffPasskeyRegistration;
use PaxofiCloud\Domain\Staff\StaffRole;
use PaxofiCloud\Tests\Support\AuditFixtures;
use PaxofiCloud\Tests\Support\SoftwareAuthenticator;
use PaxofiCloud\Tests\Support\StaffPasskeyFixtures;
use PHPUnit\Framework\TestCase;

/** Adding optional passkeys to a signed-in member (§6.20; registration policy §6.17). */
final class StaffPasskeyRegistrationTest extends TestCase
{
    private StaffPasskeyFixtures $f;
    private string $staffId;

    protected function setUp(): void
    {
        $this->f = new StaffPasskeyFixtures();
        $this->staffId = $this->f->invitations->bootstrap('ada@example.test', 'Ada Lovelace', AuditFixtures::client());
        $this->f->activate($this->f->lastToken());
        $this->f->audit->events = [];
    }

    public function testAPasskeyIsAddedWithACurrentAuthenticatorCode(): void
    {
        self::assertSame(['passkeys' => 1], $this->f->addPasskey($this->staffId, new SoftwareAuthenticator(StaffPasskeyFixtures::ORIGIN), 'YubiKey'));
        self::assertSame(['passkeys' => 2], $this->f->addPasskey($this->staffId, new SoftwareAuthenticator(StaffPasskeyFixtures::ORIGIN, -8)));

        self::assertSame(['YubiKey', 'Passkey 2'], array_column($this->f->store->passkeys, 'label'));
        self::assertSame([AuditEventType::STAFF_PASSKEY_REGISTERED, AuditEventType::STAFF_PASSKEY_REGISTERED], $this->f->audit->types());
    }

    /** §6.20 step-up: a session alone cannot add a lasting way in. */
    public function testAWrongReusedOrMissingCodeStartsNoCeremony(): void
    {
        $this->expectRejected(PasskeyRejected::STEP_UP, fn () => $this->f->registration->begin($this->staffId, '000000', AuditFixtures::client()));
        $this->expectRejected(PasskeyRejected::STEP_UP, fn () => $this->f->registration->begin($this->staffId, 'abc', AuditFixtures::client()));

        $code = $this->f->totpCode($this->staffId);
        $this->f->registration->begin($this->staffId, $code, AuditFixtures::client());
        $this->expectRejected(PasskeyRejected::STEP_UP, fn () => $this->f->registration->begin($this->staffId, $code, AuditFixtures::client()));
    }

    public function testAMemberWithoutAnAuthenticatorCannotAddPasskeys(): void
    {
        $bob = $this->f->invitations->invite($this->staffId, 'bob@example.test', 'Bob', StaffRole::Support, AuditFixtures::client());
        $this->f->directory->members[$bob] = 'active';

        $this->expectRejected(PasskeyRejected::STEP_UP, fn () => $this->f->registration->begin($bob, '123456', AuditFixtures::client()));
    }

    public function testOptionsExcludePasskeysAlreadyRegistered(): void
    {
        $this->f->addPasskey($this->staffId, new SoftwareAuthenticator(StaffPasskeyFixtures::ORIGIN));

        $options = $this->f->registration->begin($this->staffId, $this->f->totpCode($this->staffId), AuditFixtures::client())['options'];
        $excluded = $options['excludeCredentials'];
        self::assertIsArray($excluded);
        self::assertCount(1, $excluded);
    }

    public function testTheSameAuthenticatorCannotCountTwice(): void
    {
        $authenticator = new SoftwareAuthenticator(StaffPasskeyFixtures::ORIGIN);
        $this->f->addPasskey($this->staffId, $authenticator);

        $this->expectRejected(PasskeyRejected::INVALID, fn () => $this->f->addPasskey($this->staffId, $authenticator));
        self::assertCount(1, $this->f->store->passkeys);
    }

    public function testACeremonyFinishesOnceAndOnlyForItsOwnMember(): void
    {
        $begun = $this->f->registration->begin($this->staffId, $this->f->totpCode($this->staffId), AuditFixtures::client());
        $json = (new SoftwareAuthenticator(StaffPasskeyFixtures::ORIGIN))->register($begun['options']);

        // Another member cannot finish this ceremony; the attempt also uses it up.
        $this->expectRejected(PasskeyRejected::CEREMONY, fn () => $this->f->registration->finish(str_repeat('b', 32), $begun['ceremony_id'], $json, '', AuditFixtures::client()));
        $this->expectRejected(PasskeyRejected::CEREMONY, fn () => $this->f->registration->finish($this->staffId, $begun['ceremony_id'], $json, '', AuditFixtures::client()));
        self::assertSame([], $this->f->store->passkeys);
    }

    public function testASignInCeremonyCannotBeUsedToRegister(): void
    {
        $signIn = $this->f->signIn->begin(AuditFixtures::client());
        $options = $this->f->registration->begin($this->staffId, $this->f->totpCode($this->staffId), AuditFixtures::client())['options'];
        $json = (new SoftwareAuthenticator(StaffPasskeyFixtures::ORIGIN))->register($options);

        $this->expectRejected(PasskeyRejected::CEREMONY, fn () => $this->f->registration->finish($this->staffId, $signIn['ceremony_id'], $json, '', AuditFixtures::client()));
    }

    public function testAPhishedRegistrationFromAnotherOriginIsRejected(): void
    {
        $this->expectRejected(PasskeyRejected::INVALID, fn () => $this->f->addPasskey($this->staffId, new SoftwareAuthenticator('https://admin-example.test')));
        self::assertSame([], $this->f->store->passkeys);
    }

    public function testADisabledMemberCannotFinish(): void
    {
        $begun = $this->f->registration->begin($this->staffId, $this->f->totpCode($this->staffId), AuditFixtures::client());
        $this->f->directory->members[$this->staffId] = 'disabled';

        $json = (new SoftwareAuthenticator(StaffPasskeyFixtures::ORIGIN))->register($begun['options']);
        $this->expectRejected(PasskeyRejected::CEREMONY, fn () => $this->f->registration->finish($this->staffId, $begun['ceremony_id'], $json, '', AuditFixtures::client()));
    }

    public function testLabelsAreCleanedAndTheLimitHolds(): void
    {
        $this->f->addPasskey($this->staffId, new SoftwareAuthenticator(StaffPasskeyFixtures::ORIGIN), '<b>bold</b>');
        self::assertSame(['Passkey 1'], array_column($this->f->store->passkeys, 'label'));

        for ($i = 1; $i < StaffPasskeyRegistration::MAX_PASSKEYS; $i++) {
            $this->f->addPasskey($this->staffId, new SoftwareAuthenticator(StaffPasskeyFixtures::ORIGIN));
        }
        $this->expectRejected(PasskeyRejected::LIMIT, fn () => $this->f->registration->begin($this->staffId, $this->f->totpCode($this->staffId), AuditFixtures::client()));
    }

    private function expectRejected(string $reason, \Closure $action): void
    {
        try {
            $action();
            self::fail('Expected a rejection: ' . $reason);
        } catch (PasskeyRejected $e) {
            self::assertSame($reason, $e->reason);
        }
        $last = $this->f->audit->events[count($this->f->audit->events) - 1] ?? null;
        self::assertNotNull($last);
        self::assertSame(AuditEventType::STAFF_PASSKEY_REJECTED, $last->type);
        self::assertSame($reason, $last->details['reason'] ?? null);
    }
}
