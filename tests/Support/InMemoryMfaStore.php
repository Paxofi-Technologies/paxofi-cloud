<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Support;

use PaxofiCloud\Application\Contracts\MfaStore;

/** Mirrors MysqlMfaStore's conditional updates in memory (integration tests prove the SQL). */
final class InMemoryMfaStore implements MfaStore
{
    /** @var array<string, array{secret_envelope: string, confirmed: bool, last_used_step: ?int}> */
    public array $totp = [];
    /** @var array<string, array{identity_id: string, used: bool}> */
    public array $codes = [];

    public function totp(string $identityId): ?array
    {
        return $this->totp[$identityId] ?? null;
    }

    public function saveTotpSetup(string $identityId, string $secretEnvelope): void
    {
        if (!($this->totp[$identityId]['confirmed'] ?? false)) {
            $this->totp[$identityId] = ['secret_envelope' => $secretEnvelope, 'confirmed' => false, 'last_used_step' => null];
        }
    }

    public function confirmTotp(string $identityId, int $step, \DateTimeImmutable $now): bool
    {
        if (!isset($this->totp[$identityId]) || $this->totp[$identityId]['confirmed']) {
            return false;
        }
        $this->totp[$identityId]['confirmed'] = true;
        $this->totp[$identityId]['last_used_step'] = $step;

        return true;
    }

    public function recordTotpStep(string $identityId, int $step): bool
    {
        $row = $this->totp[$identityId] ?? null;
        if ($row === null || !$row['confirmed'] || ($row['last_used_step'] !== null && $row['last_used_step'] >= $step)) {
            return false;
        }
        $this->totp[$identityId]['last_used_step'] = $step;

        return true;
    }

    public function replaceRecoveryCodes(string $identityId, array $codeHashes): void
    {
        $this->codes = array_filter($this->codes, static fn (array $c): bool => $c['identity_id'] !== $identityId);
        foreach ($codeHashes as $hash) {
            $this->codes[$hash] = ['identity_id' => $identityId, 'used' => false];
        }
    }

    public function useRecoveryCode(string $identityId, string $codeHash, \DateTimeImmutable $now): bool
    {
        $code = $this->codes[$codeHash] ?? null;
        if ($code === null || $code['used'] || $code['identity_id'] !== $identityId) {
            return false;
        }
        $this->codes[$codeHash]['used'] = true;

        return true;
    }

    public function unusedRecoveryCodes(string $identityId): int
    {
        return count(array_filter($this->codes, static fn (array $c): bool => $c['identity_id'] === $identityId && !$c['used']));
    }

    public function disable(string $identityId): void
    {
        unset($this->totp[$identityId]);
        $this->codes = array_filter($this->codes, static fn (array $c): bool => $c['identity_id'] !== $identityId);
    }
}
