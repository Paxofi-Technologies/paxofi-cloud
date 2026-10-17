<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Application\Identity\Session;

use PaxofiCloud\Application\Identity\Session\Sessions;
use PaxofiCloud\Tests\Support\InMemorySessionStore;
use PaxofiCloud\Tests\Support\MutableClock;
use PHPUnit\Framework\TestCase;

/** Threat model D-6, T-20, T-21, T-24; SRS IAM-009. */
final class SessionsTest extends TestCase
{
    private InMemorySessionStore $store;
    private MutableClock $clock;
    private Sessions $sessions;

    protected function setUp(): void
    {
        $this->store = new InMemorySessionStore();
        $this->clock = new MutableClock(new \DateTimeImmutable('2026-10-26 09:00:00 UTC'));
        $this->sessions = new Sessions($this->store, $this->clock);
    }

    public function testIssuedIdsAreUniqueAndOnlyTheirHashIsStored(): void
    {
        $a = $this->sessions->issue('id-1', 'acct-1', '198.51.100.7', 'UA');
        $b = $this->sessions->issue('id-1', 'acct-1', '198.51.100.7', 'UA');

        self::assertNotSame($a->sessionId, $b->sessionId);
        self::assertNotSame($a->csrfToken, $b->csrfToken);
        foreach ($this->store->sessions as $key => $session) {
            self::assertSame($key, $session->idHash);
            self::assertNotContains($key, [$a->sessionId, $b->sessionId]);
        }
    }

    public function testActivityWithinTheIdleWindowKeepsTheSessionAlive(): void
    {
        $issued = $this->sessions->issue('id-1', 'acct-1', '', '');
        for ($i = 0; $i < 4; $i++) {
            $this->clock->advance('+29 minutes');
            self::assertNotNull($this->sessions->resume($issued->sessionId));
        }
    }

    public function testIdleTimeoutEndsTheSession(): void
    {
        $issued = $this->sessions->issue('id-1', 'acct-1', '', '');
        $this->clock->advance('+30 minutes');

        self::assertNull($this->sessions->resume($issued->sessionId));
        self::assertSame([], $this->store->sessions);
    }

    public function testAbsoluteTimeoutEndsAnActiveSessionAfterTwelveHours(): void
    {
        $issued = $this->sessions->issue('id-1', 'acct-1', '', '');
        $this->useEvery25MinutesUntil(700, $issued->sessionId);
        // 725 min elapsed: past 12 h even though it was used 25 min ago.
        $this->clock->advance('+25 minutes');
        self::assertNull($this->sessions->resume($issued->sessionId));
    }

    public function testStoreTtlNeverOutlivesTheAbsoluteTimeout(): void
    {
        $issued = $this->sessions->issue('id-1', 'acct-1', '', '');
        $this->useEvery25MinutesUntil(700, $issued->sessionId);
        $this->clock->advance('+10 minutes');
        $this->sessions->resume($issued->sessionId);

        self::assertSame(600, $this->store->ttls[hash('sha256', $issued->sessionId)]);
    }

    public function testMalformedOrUnknownIdsResolveToNothing(): void
    {
        self::assertNull($this->sessions->resume(null));
        self::assertNull($this->sessions->resume('short'));
        self::assertNull($this->sessions->resume(str_repeat('A', 43)));
    }

    public function testEndDeletesTheSession(): void
    {
        $issued = $this->sessions->issue('id-1', 'acct-1', '', '');
        $this->sessions->end($issued->sessionId);

        self::assertNull($this->sessions->resume($issued->sessionId));
    }

    public function testCsrfTokenMustMatchItsOwnSession(): void
    {
        $a = $this->sessions->issue('id-1', 'acct-1', '', '');
        $b = $this->sessions->issue('id-2', 'acct-2', '', '');

        self::assertTrue($this->sessions->csrfMatches($a->session, $a->csrfToken));
        self::assertFalse($this->sessions->csrfMatches($a->session, $b->csrfToken));
        self::assertFalse($this->sessions->csrfMatches($a->session, null));
        self::assertFalse($this->sessions->csrfMatches($a->session, ''));
    }

    private function useEvery25MinutesUntil(int $minutes, string $sessionId): void
    {
        for ($elapsed = 25; $elapsed <= $minutes; $elapsed += 25) {
            $this->clock->advance('+25 minutes');
            self::assertNotNull($this->sessions->resume($sessionId), "alive after {$elapsed} min");
        }
    }
}
