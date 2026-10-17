<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Contracts;

/**
 * Persistence for registration and email verification. Every method runs on
 * the caller's transaction; RegisterCustomer and VerifyEmail own the
 * transaction boundary.
 */
interface IdentityStore
{
    /**
     * Locks the row (SELECT … FOR UPDATE) so concurrent registrations for one
     * address serialise.
     *
     * @return array{id: string, status: string, full_name: string}|null
     */
    public function findByEmailLookupForUpdate(string $emailLookup): ?array;

    /** Creates a pending identity with its personal account and owner membership. */
    public function createPending(
        string $identityId,
        string $accountId,
        string $email,
        string $emailLookup,
        string $fullName,
        string $phoneE164,
        string $passwordHash,
        string $termsVersion,
        \DateTimeImmutable $now,
    ): void;

    /** Replaces the details of a still-pending identity (threat model T-46). */
    public function overwritePending(
        string $identityId,
        string $fullName,
        string $phoneE164,
        string $passwordHash,
        string $termsVersion,
        \DateTimeImmutable $now,
    ): void;

    /** Deletes every unused token of the identity, then stores the new one. */
    public function replaceVerificationToken(string $identityId, string $tokenHash, \DateTimeImmutable $expiresAt): void;

    /**
     * Marks the token used if it is unused and unexpired.
     *
     * @return string|null the identity ID, or null when the token cannot be used
     */
    public function consumeVerificationToken(string $tokenHash, \DateTimeImmutable $now): ?string;

    /** pending_verification -> active; returns false if the identity was not pending. */
    public function activate(string $identityId, \DateTimeImmutable $now): bool;
}
