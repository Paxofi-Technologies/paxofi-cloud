<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Identity\Session;

use PaxofiCloud\Application\Audit\ClientContext;
use PaxofiCloud\Application\Contracts\Clock;
use PaxofiCloud\Application\Contracts\SessionStore;

/**
 * Customer sessions (threat model D-6, §6.11): idle 30 min, absolute 12 h,
 * enforced here as well as by the store's TTL. IDs and CSRF tokens are 256-bit
 * random values. Only the SHA-256 of the session ID is stored (D-2); the CSRF
 * token is kept in the session record so GET /session can return it, and is
 * useless without the session cookie.
 */
final readonly class Sessions
{
    public const int IDLE_SECONDS = 1800;
    public const int ABSOLUTE_SECONDS = 43_200;

    public function __construct(private SessionStore $store, private Clock $clock)
    {
    }

    public function issue(string $identityId, string $accountId, ClientContext $client): IssuedSession
    {
        $sessionId = self::token();
        $csrf = self::token();
        $now = $this->clock->now();
        $session = new Session(self::hash($sessionId), $identityId, $accountId, $csrf, $now, $now, $client->ip, mb_substr($client->userAgent, 0, 255), mb_substr($client->location(), 0, 200));
        $this->store->save($session, self::IDLE_SECONDS);

        return new IssuedSession($sessionId, $csrf, $session);
    }

    /** The live session for a raw cookie value, refreshed; null when absent or expired. */
    public function resume(#[\SensitiveParameter] ?string $sessionId): ?Session
    {
        if ($sessionId === null || preg_match('/^[A-Za-z0-9_-]{43}$/', $sessionId) !== 1) {
            return null;
        }
        $session = $this->store->find(self::hash($sessionId));
        if ($session === null) {
            return null;
        }
        $now = $this->clock->now();
        if ($this->expired($session)) {
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
        if ($sessionId !== null && $sessionId !== '') {
            $this->store->delete(self::hash($sessionId));
        }
    }

    /**
     * §6.21: the identity's live sessions, most recently used first. Expired
     * ones are deleted on the way.
     *
     * @return list<Session>
     */
    public function listFor(string $identityId): array
    {
        $live = [];
        foreach ($this->store->forIdentity($identityId) as $session) {
            if ($this->expired($session)) {
                $this->store->delete($session->idHash);
                continue;
            }
            $live[] = $session;
        }
        usort($live, static fn (Session $a, Session $b): int => $b->lastSeenAt <=> $a->lastSeenAt);

        return $live;
    }

    /** Ends one of the identity's own sessions by its public ID; false when there is none. */
    public function revoke(string $identityId, string $publicId): bool
    {
        foreach ($this->listFor($identityId) as $session) {
            if (hash_equals($session->publicId(), $publicId)) {
                $this->store->delete($session->idHash);

                return true;
            }
        }

        return false;
    }

    /** Ends every session of the identity except `$keep`; returns how many ended. */
    public function revokeOthers(string $identityId, Session $keep): int
    {
        $ended = 0;
        foreach ($this->listFor($identityId) as $session) {
            if ($session->idHash !== $keep->idHash) {
                $this->store->delete($session->idHash);
                $ended++;
            }
        }

        return $ended;
    }

    /** T-21: constant-time comparison of the presented token with the session's. */
    public function csrfMatches(Session $session, #[\SensitiveParameter] ?string $token): bool
    {
        return $token !== null && $token !== '' && hash_equals($session->csrfToken, $token);
    }

    private function expired(Session $session): bool
    {
        $now = $this->clock->now()->getTimestamp();

        return $session->lastSeenAt->getTimestamp() + self::IDLE_SECONDS <= $now
            || $session->createdAt->getTimestamp() + self::ABSOLUTE_SECONDS <= $now;
    }

    private static function token(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private static function hash(#[\SensitiveParameter] string $value): string
    {
        return hash('sha256', $value);
    }
}
