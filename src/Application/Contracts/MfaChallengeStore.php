<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Contracts;

/**
 * Short-lived "password accepted, code still needed" tickets and the
 * per-identity failure counter of the second sign-in step (§6.13, T-14).
 * Tickets are keyed by the SHA-256 of their value (D-2).
 */
interface MfaChallengeStore
{
    public function save(string $ticketHash, string $identityId, string $accountId, int $ttlSeconds): void;

    /** @return array{identity_id: string, account_id: string, attempts: int}|null */
    public function find(string $ticketHash): ?array;

    /** Counts a wrong code on the ticket; returns the attempts so far. */
    public function recordAttempt(string $ticketHash): int;

    public function delete(string $ticketHash): void;

    /** Wrong second-step codes for the identity in the current 15-minute window. */
    public function identityFailures(string $identityId): int;

    public function recordIdentityFailure(string $identityId): int;
}
