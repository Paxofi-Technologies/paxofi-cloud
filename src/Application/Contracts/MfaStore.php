<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Contracts;

/** TOTP enrolment and recovery codes (migration 0004, threat model §6.13). */
interface MfaStore
{
    /** @return array{secret_envelope: string, confirmed: bool, last_used_step: ?int}|null */
    public function totp(string $identityId): ?array;

    /** Stores a not-yet-confirmed secret, replacing an earlier unconfirmed one. */
    public function saveTotpSetup(string $identityId, string $secretEnvelope): void;

    /** Turns TOTP on with the step of the confirming code; false if it was not pending. */
    public function confirmTotp(string $identityId, int $step, \DateTimeImmutable $now): bool;

    /** Records a used step only if it is later than the last one (T-13); false means replay. */
    public function recordTotpStep(string $identityId, int $step): bool;

    /** @param list<string> $codeHashes replaces every existing code of the identity */
    public function replaceRecoveryCodes(string $identityId, array $codeHashes): void;

    /** Marks an unused code used; false if unknown, used or another identity's. */
    public function useRecoveryCode(string $identityId, string $codeHash, \DateTimeImmutable $now): bool;

    public function unusedRecoveryCodes(string $identityId): int;

    /** Removes the TOTP secret and every recovery code. */
    public function disable(string $identityId): void;
}
