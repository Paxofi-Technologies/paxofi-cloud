<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Support;

use PaxofiCloud\Application\Contracts\StaffSessionStore;
use PaxofiCloud\Application\Staff\Session\StaffSession;

final class InMemoryStaffSessionStore implements StaffSessionStore
{
    /** @var array<string, StaffSession> */
    public array $sessions = [];
    /** @var array<string, int> */
    public array $ttls = [];

    public function save(StaffSession $session, int $ttlSeconds): void
    {
        $this->sessions[$session->idHash] = $session;
        $this->ttls[$session->idHash] = $ttlSeconds;
    }

    public function find(string $sessionIdHash): ?StaffSession
    {
        return $this->sessions[$sessionIdHash] ?? null;
    }

    public function delete(string $sessionIdHash): void
    {
        unset($this->sessions[$sessionIdHash], $this->ttls[$sessionIdHash]);
    }

    public function forStaff(string $staffId): array
    {
        return array_values(array_filter($this->sessions, static fn (StaffSession $s): bool => $s->staffId === $staffId));
    }
}
