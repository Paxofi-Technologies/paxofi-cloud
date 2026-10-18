<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Application\Staff\Passkeys;

use PaxofiCloud\Application\Audit\AuditEventType;
use PaxofiCloud\Application\Staff\Passkeys\PasskeyRejected;
use PaxofiCloud\Application\Staff\Passkeys\StaffPasskeySignIn;
use PaxofiCloud\Tests\Support\AuditFixtures;
use PaxofiCloud\Tests\Support\SoftwareAuthenticator;
use PaxofiCloud\Tests\Support\StaffPasskeyFixtures;
use PHPUnit\Framework\TestCase;

/** Usernameless staff sign-in (IAM-008; threat model T-29, T-30, §6.17). */
final class StaffPasskeySignInTest extends TestCase
{
    private StaffPasskeyFixtures $f;
    private string $staffId;
    private SoftwareAuthenticator $key1;
    private SoftwareAuthenticator $key2;

    protected function setUp(): void
    {
        $this->f = new StaffPasskeyFixtures();
        $this->staffId = $this->f->invitations->bootstrap('ada@example.test', 'Ada Lovelace', AuditFixtures::client());
        $token = $this->f->lastToken();
        $this->key1 = new SoftwareAuthenticator(StaffPasskeyFixtures::ORIGIN);
        $this->key2 = new SoftwareAuthenticator(StaffPasskeyFixtures::ORIGIN, -8);
        $this->f->enrol($token, $this->key1);
        $this->f->enrol($token, $this->key2);
        $this->f->audit->events = [];
    }

    public function testEitherPasskeySignsIn(): void
    {
        self::assertSame($this->staffId, $this->f->signInWith($this->key1));
        self::assertSame($this->staffId, $this->f->signInWith($this->key2));
        self::assertSame([AuditEventType::STAFF_SIGNED_IN, AuditEventType::STAFF_SIGNED_IN], $this->f->audit->types());
        self::assertSame(['subject' => $this->staffId], $this->f->audit->events[0]->details);
        self::assertNull($this->f->audit->events[0]->identityId);
    }

    public function testTheCounterIsStoredAfterEachSignIn(): void
    {
        $this->f->signInWith($this->key1);
        $this->f->signInWith($this->key1);

        $counts = array_map(static fn (array $row): int => $row['sign_count'], array_values($this->f->store->passkeys));
        self::assertSame([2, 0], $counts);
    }

    public function testAnInvitedMemberCannotSignInBeforeEnrolmentCompletes(): void
    {
        $this->f->invitations->invite($this->staffId, 'bob@example.test', 'Bob', \PaxofiCloud\Domain\Staff\StaffRole::Support, AuditFixtures::client());
        $bob = new SoftwareAuthenticator(StaffPasskeyFixtures::ORIGIN);
        $this->f->enrol($this->f->lastToken(), $bob);

        $this->expectFailed(PasskeyRejected::UNKNOWN_CREDENTIAL, fn () => $this->f->signInWith($bob));
    }

    public function testDisabledMembersAndUnknownCredentialsAreRefused(): void
    {
        $this->expectFailed(PasskeyRejected::UNKNOWN_CREDENTIAL, fn () => $this->f->signInWith(new SoftwareAuthenticator(StaffPasskeyFixtures::ORIGIN)));

        $this->f->directory->members[$this->staffId] = 'disabled';
        $this->expectFailed(PasskeyRejected::UNKNOWN_CREDENTIAL, fn () => $this->f->signInWith($this->key1));
    }

    /** T-29: an assertion made for another origin (a phishing page) is refused. */
    public function testAssertionsForAnotherOriginAreRefused(): void
    {
        $this->key1->origin = 'https://admin.example.test.phish.example';
        $this->expectFailed(PasskeyRejected::INVALID, fn () => $this->f->signInWith($this->key1));
    }

    /** T-30: a counter that goes backwards disables the passkey and raises an alert. */
    public function testACloneSuspicionDisablesThePasskeyAndAlerts(): void
    {
        $this->f->signInWith($this->key1);
        $this->f->signInWith($this->key1);
        $this->key1->signCount = 0;

        $this->expectFailed(PasskeyRejected::COUNTER, fn () => $this->f->signInWith($this->key1));
        self::assertContains('counter_regression', array_column($this->f->store->passkeys, 'disabled'));
        self::assertContains(AuditEventType::STAFF_PASSKEY_CLONE_SUSPECTED, $this->f->audit->types());
        self::assertSame('staff.passkey.clone_suspected', $this->f->logger->records[0]['message'] ?? null);

        // Even a correctly counted assertion from that passkey is now refused; the other one still works.
        $this->key1->signCount = 100;
        $this->expectFailed(PasskeyRejected::UNKNOWN_CREDENTIAL, fn () => $this->f->signInWith($this->key1));
        self::assertSame($this->staffId, $this->f->signInWith($this->key2));
    }

    public function testACeremonyIsSingleUse(): void
    {
        $begun = $this->f->signIn->begin(AuditFixtures::client());
        $json = $this->key1->assert($begun['options'], $this->f->handleOf($this->key1));
        $this->f->signIn->finish($begun['ceremony_id'], $json, AuditFixtures::client());

        $this->expectFailed(PasskeyRejected::CEREMONY, fn () => $this->f->signIn->finish($begun['ceremony_id'], $json, AuditFixtures::client()));
        $this->expectFailed(PasskeyRejected::CEREMONY, fn () => $this->f->signIn->finish('not-a-ceremony', $json, AuditFixtures::client()));
    }

    public function testAnAssertionForAnotherChallengeIsRefused(): void
    {
        $first = $this->f->signIn->begin(AuditFixtures::client());
        $second = $this->f->signIn->begin(AuditFixtures::client());
        $json = $this->key1->assert($first['options'], $this->f->handleOf($this->key1));

        $this->expectFailed(PasskeyRejected::INVALID, fn () => $this->f->signIn->finish($second['ceremony_id'], $json, AuditFixtures::client()));
    }

    public function testMalformedResponsesAreRefused(): void
    {
        $begun = $this->f->signIn->begin(AuditFixtures::client());
        $this->expectFailed(PasskeyRejected::MALFORMED, fn () => $this->f->signIn->finish($begun['ceremony_id'], '{"nope":1}', AuditFixtures::client()));
    }

    public function testSignInIsThrottledPerIp(): void
    {
        for ($i = 0; $i < StaffPasskeySignIn::IP_LIMIT_PER_MINUTE; $i++) {
            $this->f->signIn->begin(AuditFixtures::client());
        }
        try {
            $this->f->signIn->begin(AuditFixtures::client());
            self::fail('Expected throttling.');
        } catch (PasskeyRejected $e) {
            self::assertSame(PasskeyRejected::THROTTLED, $e->reason);
            self::assertGreaterThan(0, $e->retryAfter);
        }
    }

    /** @param callable(): mixed $action */
    private function expectFailed(string $reason, callable $action): void
    {
        try {
            $action();
            self::fail('Expected failure: ' . $reason);
        } catch (PasskeyRejected $e) {
            self::assertSame($reason, $e->reason);
        }
        $last = $this->f->audit->events[count($this->f->audit->events) - 1];
        self::assertSame(AuditEventType::STAFF_SIGN_IN_FAILED, $last->type);
        self::assertSame($reason, $last->details['reason'] ?? null);
    }
}
