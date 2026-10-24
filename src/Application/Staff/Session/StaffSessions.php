<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Staff\Session;

use PaxofiCloud\Application\Audit\ClientContext;
use PaxofiCloud\Application\Contracts\Clock;
use PaxofiCloud\Application\Contracts\StaffDirectory;
use PaxofiCloud\Application\Contracts\StaffSessionStore;

/**
 * Staff sessions (threat model D-6, §6.18): idle 15 minutes, absolute 8 hours,
 * checked here as well as by the store's TTL. A session survives only while
 * its member is active, re-read on every request.
 */
final readonly class StaffSessions
{
    public const int IDLE_SECONDS = 900;
    public const int ABSOLUTE_SECONDS = 28_800;

    public function __construct(private StaffSessionStore $store, private StaffDirectory $directory, private Clock $clock)
    {
    }

    public function issue(string $staffId, ?ClientContext $client = null): IssuedStaffSession
    {
        $sessionId = self::token();
        $csrf = self::token();
        $now = $this->clock->now();
        $session = new StaffSession(
            hash('sha256', $sessionId),
            $staffId,
            $csrf,
            $now,
            $now,
            $client === null ? '' : $client->ip,
            $client === null ? '' : mb_substr($client->userAgent, 0, 255),
            $client === null ? '' : mb_substr($client->location(), 0, 200),
        );
        $this->store->save($session, self::IDLE_SECONDS);

        return new IssuedStaffSession($sessionId, $csrf, $session);
    }

    public function resume(#[\SensitiveParameter] ?string $sessionId): ?StaffSession
    {
        if ($sessionId === null || preg_match('/^[A-Za-z0-9_-]{43}$/', $sessionId) !== 1) {
            return null;
        }
        $session = $this->store->find(hash('sha256', $sessionId));
        if ($session === null) {
            return null;
        }
        $now = $this->clock->now();
        if ($this->expired($session) || $this->directory->status($session->staffId) !== 'active') {
            $this->store->delete($session->idHash);

            return null;
        }
        $session = $session->seenAt($now);
        $remaining = $session->createdAt->getTimestamp() + self::ABSOLUTE_SECONDS - $now->getTimestamp();
        $this->store->save($session, min(self::IDLE_SECONDS, $remaining));

        return $session;
    }

    public function end(#[\SensitiveParameter] ?string $sessionId): void
    {
        if ($sessionId !== null && preg_match('/^[A-Za-z0-9_-]{43}$/', $sessionId) === 1) {
            $this->store->delete(hash('sha256', $sessionId));
        }
    }

    /**
     * §6.25: the member's live sessions, most recently used first. Expired
     * ones are deleted on the way.
     *
     * @return list<StaffSession>
     */
    public function listFor(string $staffId): array
    {
        $live = [];
        foreach ($this->store->forStaff($staffId) as $session) {
            if ($this->expired($session)) {
                $this->store->delete($session->idHash);
                continue;
            }
            $live[] = $session;
        }
        usort($live, static fn (StaffSession $a, StaffSession $b): int => $b->lastSeenAt <=> $a->lastSeenAt);

        return $live;
    }

    /** Ends one of the member's own sessions by its public ID; false when there is none. */
    public function revoke(string $staffId, string $publicId): bool
    {
        foreach ($this->listFor($staffId) as $session) {
            if (hash_equals($session->publicId(), $publicId)) {
                $this->store->delete($session->idHash);

                return true;
            }
        }

        return false;
    }

    /** Ends every session of the member except `$keep` (or all of them when null); returns how many ended. */
    public function revokeAllExcept(string $staffId, ?StaffSession $keep): int
    {
        $ended = 0;
        foreach ($this->listFor($staffId) as $session) {
            if ($keep === null || $session->idHash !== $keep->idHash) {
                $this->store->delete($session->idHash);
                $ended++;
            }
        }

        return $ended;
    }

    public function csrfMatches(StaffSession $session, #[\SensitiveParameter] ?string $token): bool
    {
        return $token !== null && $token !== '' && hash_equals($session->csrfToken, $token);
    }

    private function expired(StaffSession $session): bool
    {
        $now = $this->clock->now()->getTimestamp();

        return $session->lastSeenAt->getTimestamp() + self::IDLE_SECONDS <= $now
            || $session->createdAt->getTimestamp() + self::ABSOLUTE_SECONDS <= $now;
    }

    private static function token(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
