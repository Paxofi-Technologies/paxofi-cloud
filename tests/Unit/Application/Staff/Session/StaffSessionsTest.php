<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Application\Staff\Session;

use PaxofiCloud\Application\Staff\Session\StaffSessions;
use PaxofiCloud\Domain\Staff\StaffRole;
use PaxofiCloud\Tests\Support\InMemoryStaffDirectory;
use PaxofiCloud\Tests\Support\InMemoryStaffSessionStore;
use PaxofiCloud\Tests\Support\MutableClock;
use PHPUnit\Framework\TestCase;

/** Staff sessions: 15 min idle, 8 h absolute, alive only while the member is active (§6.18). */
final class StaffSessionsTest extends TestCase
{
    private const string STAFF = 'a0000000000000000000000000000000';

    private InMemoryStaffSessionStore $store;
    private InMemoryStaffDirectory $directory;
    private MutableClock $clock;
    private StaffSessions $sessions;

    protected function setUp(): void
    {
        $this->store = new InMemoryStaffSessionStore();
        $this->directory = new InMemoryStaffDirectory();
        $this->directory->add(self::STAFF, 'active', StaffRole::Support);
        $this->clock = new MutableClock(new \DateTimeImmutable('2026-10-26 09:00:00 UTC'));
        $this->sessions = new StaffSessions($this->store, $this->directory, $this->clock);
    }

    public function testIssuedSessionsAreStoredByHashOnlyAndResume(): void
    {
        $issued = $this->sessions->issue(self::STAFF);

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $issued->sessionId);
        self::assertArrayHasKey(hash('sha256', $issued->sessionId), $this->store->sessions);
        self::assertArrayNotHasKey($issued->sessionId, $this->store->sessions);
        self::assertSame(StaffSessions::IDLE_SECONDS, $this->store->ttls[hash('sha256', $issued->sessionId)]);
        self::assertSame(self::STAFF, $this->sessions->resume($issued->sessionId)?->staffId);
        self::assertNotSame($issued->sessionId, $this->sessions->issue(self::STAFF)->sessionId);
    }

    public function testIdleTimeoutEndsTheSession(): void
    {
        $issued = $this->sessions->issue(self::STAFF);
        $this->clock->advance('+14 minutes');
        self::assertNotNull($this->sessions->resume($issued->sessionId), 'activity refreshes the idle window');
        $this->clock->advance('+15 minutes');

        self::assertNull($this->sessions->resume($issued->sessionId));
        self::assertSame([], $this->store->sessions);
    }

    public function testAbsoluteTimeoutEndsEvenAnActiveSession(): void
    {
        $issued = $this->sessions->issue(self::STAFF);
        // 34 × 14 min = 7 h 56 min of continuous activity is still within the limit…
        for ($i = 0; $i < 34; $i++) {
            $this->clock->advance('+14 minutes');
            self::assertNotNull($this->sessions->resume($issued->sessionId));
        }
        $this->clock->advance('+14 minutes');

        // …but 8 h 10 min is not, however recent the last request.
        self::assertNull($this->sessions->resume($issued->sessionId), '8 hours is the hard limit');
    }

    public function testTheTtlNeverRunsPastTheAbsoluteLimit(): void
    {
        $issued = $this->sessions->issue(self::STAFF);
        for ($i = 0; $i < 33; $i++) {
            $this->clock->advance('+14 minutes');
            $this->sessions->resume($issued->sessionId);
        }
        $this->clock->advance('+13 minutes'); // 7 h 55 min after sign-in
        self::assertNotNull($this->sessions->resume($issued->sessionId));

        self::assertSame(300, $this->store->ttls[hash('sha256', $issued->sessionId)]);
    }

    public function testDisablingTheMemberEndsTheirSessionsAtTheNextRequest(): void
    {
        $issued = $this->sessions->issue(self::STAFF);
        $this->directory->members[self::STAFF] = 'disabled';

        self::assertNull($this->sessions->resume($issued->sessionId));
        self::assertSame([], $this->store->sessions);
    }

    public function testMalformedOrUnknownIdsAndSignOut(): void
    {
        self::assertNull($this->sessions->resume(null));
        self::assertNull($this->sessions->resume('short'));
        self::assertNull($this->sessions->resume(str_repeat('A', 43)));

        $issued = $this->sessions->issue(self::STAFF);
        $this->sessions->end($issued->sessionId);
        self::assertNull($this->sessions->resume($issued->sessionId));
    }

    public function testCsrfTokenMustMatchExactly(): void
    {
        $issued = $this->sessions->issue(self::STAFF);
        $session = $this->sessions->resume($issued->sessionId);
        self::assertNotNull($session);

        self::assertTrue($this->sessions->csrfMatches($session, $issued->csrfToken));
        self::assertFalse($this->sessions->csrfMatches($session, null));
        self::assertFalse($this->sessions->csrfMatches($session, ''));
        self::assertFalse($this->sessions->csrfMatches($session, $issued->csrfToken . 'x'));
    }
}
