<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Contracts;

/**
 * Staff passwords, authenticator secrets and recovery codes (migration 0009;
 * threat model §6.20). Secrets are stored only as an encrypted envelope,
 * recovery codes only as keyed hashes.
 */
interface StaffCredentialStore
{
    /** @return array{id: string, status: string, password_hash: ?string}|null */
    public function findForPassword(string $emailLookup): ?array;

    /** @return array{email: string, full_name: string, status: string, has_password: bool}|null */
    public function member(string $staffId): ?array;

    /** @return array{secret_envelope: string, confirmed: bool, last_used_step: ?int}|null */
    public function totp(string $staffId): ?array;

    /** Stores a new secret unless a confirmed one exists. */
    public function saveTotpSetup(string $staffId, string $secretEnvelope): void;

    /**
     * Sets the first password, confirms the secret at `$step` and replaces the
     * recovery codes, in the caller's transaction. False when the member
     * already has a password or no unconfirmed secret.
     *
     * @param list<string> $recoveryCodeHashes
     */
    public function completeSetup(string $staffId, string $passwordHash, int $step, array $recoveryCodeHashes, \DateTimeImmutable $now): bool;

    /** Records a used time step; false when it is not newer than the last one (T-13). */
    public function recordTotpStep(string $staffId, int $step): bool;

    public function useRecoveryCode(string $staffId, string $codeHash, \DateTimeImmutable $now): bool;

    public function replacePasswordHash(string $staffId, string $passwordHash): void;
}
