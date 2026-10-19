<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Support;

use PaxofiCloud\Application\Contracts\StaffCredentialStore;

/** Staff passwords, TOTP secrets and recovery codes in memory; profiles come from the passkey store. */
final class InMemoryStaffCredentialStore implements StaffCredentialStore
{
    /** @var array<string, string> */
    public array $passwords = [];

    /** @var array<string, array{secret_envelope: string, confirmed: bool, last_used_step: ?int}> */
    public array $totp = [];

    /** @var array<string, array{staff_id: string, used: bool}> */
    public array $recoveryCodes = [];

    public function __construct(private InMemoryStaffPasskeyStore $profiles, private InMemoryStaffDirectory $directory)
    {
    }

    public function findForPassword(string $emailLookup): ?array
    {
        foreach ($this->profiles->profiles as $id => $profile) {
            if ($profile['lookup'] === $emailLookup) {
                return ['id' => $id, 'status' => $this->directory->members[$id] ?? 'invited', 'password_hash' => $this->passwords[$id] ?? null];
            }
        }

        return null;
    }

    public function member(string $staffId): ?array
    {
        $profile = $this->profiles->profiles[$staffId] ?? null;
        if ($profile === null) {
            return null;
        }

        return ['email' => $profile['email'], 'full_name' => $profile['name'], 'status' => $this->directory->members[$staffId] ?? 'invited', 'has_password' => isset($this->passwords[$staffId])];
    }

    public function totp(string $staffId): ?array
    {
        return $this->totp[$staffId] ?? null;
    }

    public function saveTotpSetup(string $staffId, string $secretEnvelope): void
    {
        if (($this->totp[$staffId]['confirmed'] ?? false) === true) {
            return;
        }
        $this->totp[$staffId] = ['secret_envelope' => $secretEnvelope, 'confirmed' => false, 'last_used_step' => null];
    }

    public function completeSetup(string $staffId, string $passwordHash, int $step, array $recoveryCodeHashes, \DateTimeImmutable $now): bool
    {
        if (isset($this->passwords[$staffId]) || !isset($this->totp[$staffId]) || $this->totp[$staffId]['confirmed']) {
            return false;
        }
        $this->passwords[$staffId] = $passwordHash;
        $this->totp[$staffId]['confirmed'] = true;
        $this->totp[$staffId]['last_used_step'] = $step;
        foreach ($this->recoveryCodes as $hash => $row) {
            if ($row['staff_id'] === $staffId) {
                unset($this->recoveryCodes[$hash]);
            }
        }
        foreach ($recoveryCodeHashes as $hash) {
            $this->recoveryCodes[$hash] = ['staff_id' => $staffId, 'used' => false];
        }

        return true;
    }

    public function recordTotpStep(string $staffId, int $step): bool
    {
        $row = $this->totp[$staffId] ?? null;
        if ($row === null || !$row['confirmed'] || ($row['last_used_step'] !== null && $row['last_used_step'] >= $step)) {
            return false;
        }
        $this->totp[$staffId]['last_used_step'] = $step;

        return true;
    }

    public function useRecoveryCode(string $staffId, string $codeHash, \DateTimeImmutable $now): bool
    {
        $row = $this->recoveryCodes[$codeHash] ?? null;
        if ($row === null || $row['staff_id'] !== $staffId || $row['used']) {
            return false;
        }
        $this->recoveryCodes[$codeHash]['used'] = true;

        return true;
    }

    public function replacePasswordHash(string $staffId, string $passwordHash): void
    {
        if (isset($this->passwords[$staffId])) {
            $this->passwords[$staffId] = $passwordHash;
        }
    }
}
