<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Application\Identity\Session;

use PaxofiCloud\Application\Audit\ClientContext;
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
        $a = $this->sessions->issue('id-1', 'acct-1', ClientContext::from('198.51.100.7', 'UA', null, null, null, ''));
        $b = $this->sessions->issue('id-1', 'acct-1', ClientContext::from('198.51.100.7', 'UA', null, null, null, ''));

        self::assertNotSame($a->sessionId, $b->sessionId);
        self::assertNotSame($a->csrfToken, $b->csrfToken);
        foreach ($this->store->sessions as $key => $session) {
            self::assertSame($key, $session->idHash);
            self::assertNotContains($key, [$a->sessionId, $b->sessionId]);
        }
    }

    public function testActivityWithinTheIdleWindowKeepsTheSessionAlive(): void
    {
        $issued = $this->sessions->issue('id-1', 'acct-1', ClientContext::from('', '', null, null, null, ''));
        for ($i = 0; $i < 4; $i++) {
            $this->clock->advance('+29 minutes');
            self::assertNotNull($this->sessions->resume($issued->sessionId));
        }
    }

    public function testIdleTimeoutEndsTheSession(): void
    {
        $issued = $this->sessions->issue('id-1', 'acct-1', ClientContext::from('', '', null, null, null, ''));
        $this->clock->advance('+30 minutes');

        self::assertNull($this->sessions->resume($issued->sessionId));
        self::assertSame([], $this->store->sessions);
    }

    public function testAbsoluteTimeoutEndsAnActiveSessionAfterTwelveHours(): void
    {
        $issued = $this->sessions->issue('id-1', 'acct-1', ClientContext::from('', '', null, null, null, ''));
        $this->useEvery25MinutesUntil(700, $issued->sessionId);
        // 725 min elapsed: past 12 h even though it was used 25 min ago.
        $this->clock->advance('+25 minutes');
        self::assertNull($this->sessions->resume($issued->sessionId));
    }

    public function testStoreTtlNeverOutlivesTheAbsoluteTimeout(): void
    {
        $issued = $this->sessions->issue('id-1', 'acct-1', ClientContext::from('', '', null, null, null, ''));
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
        $issued = $this->sessions->issue('id-1', 'acct-1', ClientContext::from('', '', null, null, null, ''));
        $this->sessions->end($issued->sessionId);

        self::assertNull($this->sessions->resume($issued->sessionId));
    }

    public function testCsrfTokenMustMatchItsOwnSession(): void
    {
        $a = $this->sessions->issue('id-1', 'acct-1', ClientContext::from('', '', null, null, null, ''));
        $b = $this->sessions->issue('id-2', 'acct-2', ClientContext::from('', '', null, null, null, ''));

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

    /** §6.21: the list holds only the identity's own live sessions, newest activity first, with where they started. */
    public function testListShowsOnlyTheIdentitysLiveSessions(): void
    {
        $lagos = ClientContext::from('198.51.100.7', 'Mozilla/5.0 (Windows NT 10.0) Chrome/146.0', 'NG', 'Lagos', 'Ikeja', '');
        $old = $this->sessions->issue('id-1', 'acct-1', $lagos);
        $this->clock->advance('+5 minutes');
        $new = $this->sessions->issue('id-1', 'acct-1', $lagos);
        $this->sessions->issue('id-2', 'acct-2', $lagos);

        $list = $this->sessions->listFor('id-1');
        self::assertSame([$new->session->idHash, $old->session->idHash], array_map(static fn ($s) => $s->idHash, $list));
        self::assertSame('Ikeja, Lagos, Nigeria', $list[0]->location);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $list[0]->publicId());
        self::assertNotSame($list[0]->idHash, $list[0]->publicId());

        // An expired session leaves the list and the store.
        $this->clock->advance('+26 minutes');
        self::assertCount(1, $this->sessions->listFor('id-1'));
        self::assertArrayNotHasKey($old->session->idHash, $this->store->sessions);
    }

    public function testRevokeEndsOnlyTheIdentitysOwnSession(): void
    {
        $client = ClientContext::from('', '', null, null, null, '');
        $mine = $this->sessions->issue('id-1', 'acct-1', $client);
        $theirs = $this->sessions->issue('id-2', 'acct-2', $client);

        self::assertFalse($this->sessions->revoke('id-1', $theirs->session->publicId()), 'another identity\'s session cannot be named');
        self::assertNotNull($this->sessions->resume($theirs->sessionId));
        self::assertFalse($this->sessions->revoke('id-1', str_repeat('0', 32)));

        self::assertTrue($this->sessions->revoke('id-1', $mine->session->publicId()));
        self::assertNull($this->sessions->resume($mine->sessionId));
    }

    public function testRevokeOthersKeepsOnlyTheCurrentSession(): void
    {
        $client = ClientContext::from('', '', null, null, null, '');
        $current = $this->sessions->issue('id-1', 'acct-1', $client);
        $other1 = $this->sessions->issue('id-1', 'acct-1', $client);
        $other2 = $this->sessions->issue('id-1', 'acct-1', $client);
        $someoneElse = $this->sessions->issue('id-2', 'acct-2', $client);

        self::assertSame(2, $this->sessions->revokeOthers('id-1', $current->session));
        self::assertNotNull($this->sessions->resume($current->sessionId));
        self::assertNull($this->sessions->resume($other1->sessionId));
        self::assertNull($this->sessions->resume($other2->sessionId));
        self::assertNotNull($this->sessions->resume($someoneElse->sessionId));
    }
}
