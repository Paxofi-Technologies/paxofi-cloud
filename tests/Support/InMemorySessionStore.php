<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Support;

use PaxofiCloud\Application\Contracts\SessionStore;
use PaxofiCloud\Application\Identity\Session\Session;

/** Keeps sessions and their last TTL; expiry is left to Sessions' own checks. */
final class InMemorySessionStore implements SessionStore
{
    /** @var array<string, Session> */
    public array $sessions = [];
    /** @var array<string, int> */
    public array $ttls = [];

    public function save(Session $session, int $ttlSeconds): void
    {
        $this->sessions[$session->idHash] = $session;
        $this->ttls[$session->idHash] = $ttlSeconds;
    }

    public function find(string $sessionIdHash): ?Session
    {
        return $this->sessions[$sessionIdHash] ?? null;
    }

    public function delete(string $sessionIdHash): void
    {
        unset($this->sessions[$sessionIdHash], $this->ttls[$sessionIdHash]);
    }
}
