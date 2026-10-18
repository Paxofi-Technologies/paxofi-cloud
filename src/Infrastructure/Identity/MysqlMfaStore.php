<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Identity;

use Paxofi\Core\Contracts\Connection;
use Paxofi\Core\Contracts\Repository;
use PaxofiCloud\Application\Contracts\MfaStore;
use PaxofiCloud\Infrastructure\Persistence\Row;

/** MySQL storage for TOTP and recovery codes (migration 0004). */
final readonly class MysqlMfaStore implements MfaStore
{
    private const string TIME = 'Y-m-d H:i:s.u';

    public function __construct(private Connection $connection, private Repository $repository)
    {
    }

    public function totp(string $identityId): ?array
    {
        $rows = $this->repository->fetchAll(
            'SELECT secret_envelope, confirmed_at, last_used_step FROM identity_totp WHERE identity_id = :id',
            ['id' => $identityId],
        );
        if ($rows === []) {
            return null;
        }
        $step = $rows[0]['last_used_step'] ?? null;

        return [
            'secret_envelope' => Row::string($rows[0], 'secret_envelope'),
            'confirmed' => ($rows[0]['confirmed_at'] ?? null) !== null,
            'last_used_step' => $step === null ? null : Row::int($rows[0], 'last_used_step'),
        ];
    }

    public function saveTotpSetup(string $identityId, string $secretEnvelope): void
    {
        // Only an unconfirmed secret is ever replaced; a confirmed one stays until disabled.
        $this->connection->execute(
            'INSERT INTO identity_totp (identity_id, secret_envelope) VALUES (:id, :envelope)
             ON DUPLICATE KEY UPDATE secret_envelope = IF(confirmed_at IS NULL, VALUES(secret_envelope), secret_envelope)',
            ['id' => $identityId, 'envelope' => $secretEnvelope],
        );
    }

    public function confirmTotp(string $identityId, int $step, \DateTimeImmutable $now): bool
    {
        return $this->connection->execute(
            'UPDATE identity_totp SET confirmed_at = :now, last_used_step = :step WHERE identity_id = :id AND confirmed_at IS NULL',
            ['id' => $identityId, 'step' => $step, 'now' => self::time($now)],
        ) === 1;
    }

    public function recordTotpStep(string $identityId, int $step): bool
    {
        return $this->connection->execute(
            'UPDATE identity_totp SET last_used_step = :step
             WHERE identity_id = :id AND confirmed_at IS NOT NULL AND (last_used_step IS NULL OR last_used_step < :step2)',
            ['id' => $identityId, 'step' => $step, 'step2' => $step],
        ) === 1;
    }

    public function replaceRecoveryCodes(string $identityId, array $codeHashes): void
    {
        $this->connection->execute('DELETE FROM identity_recovery_codes WHERE identity_id = :id', ['id' => $identityId]);
        foreach ($codeHashes as $hash) {
            $this->connection->execute(
                'INSERT INTO identity_recovery_codes (code_hash, identity_id) VALUES (:hash, :id)',
                ['hash' => $hash, 'id' => $identityId],
            );
        }
    }

    public function useRecoveryCode(string $identityId, string $codeHash, \DateTimeImmutable $now): bool
    {
        return $this->connection->execute(
            'UPDATE identity_recovery_codes SET used_at = :now WHERE code_hash = :hash AND identity_id = :id AND used_at IS NULL',
            ['hash' => $codeHash, 'id' => $identityId, 'now' => self::time($now)],
        ) === 1;
    }

    public function unusedRecoveryCodes(string $identityId): int
    {
        $rows = $this->repository->fetchAll(
            'SELECT COUNT(*) AS n FROM identity_recovery_codes WHERE identity_id = :id AND used_at IS NULL',
            ['id' => $identityId],
        );

        return $rows === [] ? 0 : Row::int($rows[0], 'n');
    }

    public function disable(string $identityId): void
    {
        $this->connection->execute('DELETE FROM identity_recovery_codes WHERE identity_id = :id', ['id' => $identityId]);
        $this->connection->execute('DELETE FROM identity_totp WHERE identity_id = :id', ['id' => $identityId]);
    }

    private static function time(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format(self::TIME);
    }
}
