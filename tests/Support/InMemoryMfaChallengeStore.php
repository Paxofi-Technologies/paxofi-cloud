<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Support;

use PaxofiCloud\Application\Contracts\MfaChallengeStore;

/** RedisMfaChallengeStore's rules without TTLs (integration tests cover expiry). */
final class InMemoryMfaChallengeStore implements MfaChallengeStore
{
    /** @var array<string, array{identity_id: string, account_id: string, attempts: int}> */
    public array $tickets = [];
    /** @var array<string, int> */
    public array $failures = [];

    public function save(string $ticketHash, string $identityId, string $accountId, int $ttlSeconds): void
    {
        $this->tickets[$ticketHash] = ['identity_id' => $identityId, 'account_id' => $accountId, 'attempts' => 0];
    }

    public function find(string $ticketHash): ?array
    {
        return $this->tickets[$ticketHash] ?? null;
    }

    public function recordAttempt(string $ticketHash): int
    {
        if (!isset($this->tickets[$ticketHash])) {
            return 0;
        }

        return ++$this->tickets[$ticketHash]['attempts'];
    }

    public function delete(string $ticketHash): void
    {
        unset($this->tickets[$ticketHash]);
    }

    public function identityFailures(string $identityId): int
    {
        return $this->failures[$identityId] ?? 0;
    }

    public function recordIdentityFailure(string $identityId): int
    {
        return $this->failures[$identityId] = ($this->failures[$identityId] ?? 0) + 1;
    }
}
