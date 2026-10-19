<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Staff;

use Paxofi\Core\Contracts\Connection;
use Paxofi\Core\Contracts\Repository;
use PaxofiCloud\Application\Contracts\StaffCredentialStore;
use PaxofiCloud\Infrastructure\Persistence\Row;

/** MySQL storage for staff passwords, TOTP secrets and recovery codes (migration 0009). */
final readonly class MysqlStaffCredentialStore implements StaffCredentialStore
{
    private const string TIME = 'Y-m-d H:i:s.u';

    public function __construct(private Connection $connection, private Repository $repository)
    {
    }

    public function findForPassword(string $emailLookup): ?array
    {
        $rows = $this->repository->fetchAll('SELECT id, status, password_hash FROM staff_members WHERE email_lookup = :lookup', ['lookup' => $emailLookup]);
        if ($rows === []) {
            return null;
        }
        $hash = $rows[0]['password_hash'] ?? null;

        return ['id' => Row::string($rows[0], 'id'), 'status' => Row::string($rows[0], 'status'), 'password_hash' => is_string($hash) ? $hash : null];
    }

    public function member(string $staffId): ?array
    {
        $rows = $this->repository->fetchAll('SELECT email, full_name, status, password_hash IS NOT NULL AS has_password FROM staff_members WHERE id = :id', ['id' => $staffId]);
        if ($rows === []) {
            return null;
        }

        return [
            'email' => Row::string($rows[0], 'email'),
            'full_name' => Row::string($rows[0], 'full_name'),
            'status' => Row::string($rows[0], 'status'),
            'has_password' => Row::int($rows[0], 'has_password') === 1,
        ];
    }

    public function totp(string $staffId): ?array
    {
        $rows = $this->repository->fetchAll('SELECT secret_envelope, confirmed_at, last_used_step FROM staff_totp WHERE staff_id = :id', ['id' => $staffId]);
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

    public function saveTotpSetup(string $staffId, string $secretEnvelope): void
    {
        $this->connection->execute(
            'INSERT INTO staff_totp (staff_id, secret_envelope) VALUES (:id, :envelope)
             ON DUPLICATE KEY UPDATE secret_envelope = IF(confirmed_at IS NULL, VALUES(secret_envelope), secret_envelope)',
            ['id' => $staffId, 'envelope' => $secretEnvelope],
        );
    }

    public function completeSetup(string $staffId, string $passwordHash, int $step, array $recoveryCodeHashes, \DateTimeImmutable $now): bool
    {
        $password = $this->connection->execute(
            'UPDATE staff_members SET password_hash = :hash WHERE id = :id AND password_hash IS NULL',
            ['id' => $staffId, 'hash' => $passwordHash],
        );
        $confirmed = $this->connection->execute(
            'UPDATE staff_totp SET confirmed_at = :now, last_used_step = :step WHERE staff_id = :id AND confirmed_at IS NULL',
            ['id' => $staffId, 'step' => $step, 'now' => self::time($now)],
        );
        if ($password !== 1 || $confirmed !== 1) {
            return false;
        }
        $this->connection->execute('DELETE FROM staff_recovery_codes WHERE staff_id = :id', ['id' => $staffId]);
        foreach ($recoveryCodeHashes as $hash) {
            $this->connection->execute('INSERT INTO staff_recovery_codes (code_hash, staff_id) VALUES (:hash, :id)', ['hash' => $hash, 'id' => $staffId]);
        }

        return true;
    }

    public function recordTotpStep(string $staffId, int $step): bool
    {
        return $this->connection->execute(
            'UPDATE staff_totp SET last_used_step = :step
             WHERE staff_id = :id AND confirmed_at IS NOT NULL AND (last_used_step IS NULL OR last_used_step < :step2)',
            ['id' => $staffId, 'step' => $step, 'step2' => $step],
        ) === 1;
    }

    public function useRecoveryCode(string $staffId, string $codeHash, \DateTimeImmutable $now): bool
    {
        return $this->connection->execute(
            'UPDATE staff_recovery_codes SET used_at = :now WHERE code_hash = :hash AND staff_id = :id AND used_at IS NULL',
            ['hash' => $codeHash, 'id' => $staffId, 'now' => self::time($now)],
        ) === 1;
    }

    public function replacePasswordHash(string $staffId, string $passwordHash): void
    {
        $this->connection->execute('UPDATE staff_members SET password_hash = :hash WHERE id = :id AND password_hash IS NOT NULL', ['id' => $staffId, 'hash' => $passwordHash]);
    }

    private static function time(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format(self::TIME);
    }
}
