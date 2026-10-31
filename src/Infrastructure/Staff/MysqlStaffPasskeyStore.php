<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Staff;

use Paxofi\Core\Contracts\Connection;
use Paxofi\Core\Contracts\Repository;
use PaxofiCloud\Application\Contracts\StaffPasskeyStore;
use PaxofiCloud\Application\Staff\Passkeys\StoredPasskey;
use PaxofiCloud\Application\Staff\Passkeys\VerifiedPasskey;
use PaxofiCloud\Infrastructure\Persistence\Row;

/** MySQL storage for staff invitations and passkeys (migration 0008). */
final readonly class MysqlStaffPasskeyStore implements StaffPasskeyStore
{
    private const string TIME = 'Y-m-d H:i:s.u';

    public function __construct(private Connection $connection, private Repository $repository)
    {
    }

    public function staffExistsWithEmail(string $emailLookup): bool
    {
        return $this->repository->fetchAll('SELECT id FROM staff_members WHERE email_lookup = :lookup', ['lookup' => $emailLookup]) !== [];
    }

    public function invitedMemberWithEmailForUpdate(string $emailLookup): ?array
    {
        $rows = $this->repository->fetchAll(
            "SELECT id, email FROM staff_members WHERE email_lookup = :lookup AND status = 'invited' FOR UPDATE",
            ['lookup' => $emailLookup],
        );

        return isset($rows[0]) ? ['staff_id' => Row::string($rows[0], 'id'), 'email' => Row::string($rows[0], 'email')] : null;
    }

    public function cancelOpenInvitations(string $staffId): void
    {
        $this->connection->execute('DELETE FROM staff_invitations WHERE staff_id = :id AND used_at IS NULL', ['id' => $staffId]);
    }

    public function createInvitedMember(string $staffId, string $email, string $emailLookup, string $fullName, string $userHandle, \DateTimeImmutable $now): void
    {
        $this->connection->execute(
            'INSERT INTO staff_members (id, email, email_lookup, full_name, status, created_at, webauthn_user_handle)
             VALUES (:id, :email, :lookup, :name, \'invited\', :now, :handle)',
            ['id' => $staffId, 'email' => $email, 'lookup' => $emailLookup, 'name' => $fullName, 'now' => self::time($now), 'handle' => $userHandle],
        );
    }

    public function addInvitation(string $tokenHash, string $staffId, ?string $invitedBy, \DateTimeImmutable $now, \DateTimeImmutable $expiresAt): void
    {
        $this->connection->execute(
            'INSERT INTO staff_invitations (token_hash, staff_id, invited_by, created_at, expires_at)
             VALUES (:hash, :staff, :invited_by, :now, :expires)',
            ['hash' => $tokenHash, 'staff' => $staffId, 'invited_by' => $invitedBy, 'now' => self::time($now), 'expires' => self::time($expiresAt)],
        );
    }

    public function openInvitationForUpdate(string $tokenHash, \DateTimeImmutable $now): ?array
    {
        $rows = $this->repository->fetchAll(
            'SELECT m.id, m.email, m.full_name, m.webauthn_user_handle
             FROM staff_invitations i JOIN staff_members m ON m.id = i.staff_id
             WHERE i.token_hash = :hash AND i.used_at IS NULL AND i.expires_at > :now AND m.status = \'invited\'
             FOR UPDATE',
            ['hash' => $tokenHash, 'now' => self::time($now)],
        );
        if ($rows === []) {
            return null;
        }

        return [
            'staff_id' => Row::string($rows[0], 'id'),
            'email' => Row::string($rows[0], 'email'),
            'full_name' => Row::string($rows[0], 'full_name'),
            'user_handle' => Row::string($rows[0], 'webauthn_user_handle'),
        ];
    }

    public function activeMemberForPasskeys(string $staffId): ?array
    {
        $rows = $this->repository->fetchAll(
            'SELECT email, full_name, webauthn_user_handle FROM staff_members WHERE id = :id AND status = \'active\'',
            ['id' => $staffId],
        );
        if ($rows === []) {
            return null;
        }

        return [
            'email' => Row::string($rows[0], 'email'),
            'full_name' => Row::string($rows[0], 'full_name'),
            'user_handle' => Row::string($rows[0], 'webauthn_user_handle'),
        ];
    }

    public function credentialIdsOf(string $staffId): array
    {
        $rows = $this->repository->fetchAll(
            'SELECT credential_id FROM staff_passkeys WHERE staff_id = :staff ORDER BY created_at',
            ['staff' => $staffId],
        );

        return array_map(static fn (array $row): string => Row::string($row, 'credential_id'), $rows);
    }

    public function addPasskey(string $passkeyId, string $staffId, VerifiedPasskey $passkey, string $label, \DateTimeImmutable $now): void
    {
        $this->connection->execute(
            'INSERT INTO staff_passkeys (id, staff_id, credential_id, credential_lookup, public_key, algorithm, sign_count, aaguid,
                 transports, backup_eligible, backup_state, label, created_at)
             VALUES (:id, :staff, :credential, :lookup, :public_key, :algorithm, :sign_count, :aaguid,
                 :transports, :backup_eligible, :backup_state, :label, :now)',
            [
                'id' => $passkeyId,
                'staff' => $staffId,
                'credential' => $passkey->credentialId,
                'lookup' => hash('sha256', $passkey->credentialId),
                'public_key' => $passkey->publicKey,
                'algorithm' => $passkey->algorithm,
                'sign_count' => $passkey->signCount,
                'aaguid' => $passkey->aaguid,
                'transports' => implode(',', $passkey->transports),
                'backup_eligible' => $passkey->backupEligible ? 1 : 0,
                'backup_state' => $passkey->backupState ? 1 : 0,
                'label' => mb_substr($label, 0, 64),
                'now' => self::time($now),
            ],
        );
    }

    public function completeEnrolment(string $tokenHash, string $staffId, \DateTimeImmutable $now): void
    {
        $time = self::time($now);
        $this->connection->execute(
            'UPDATE staff_invitations SET used_at = :now WHERE token_hash = :hash AND staff_id = :staff AND used_at IS NULL',
            ['now' => $time, 'hash' => $tokenHash, 'staff' => $staffId],
        );
        $this->connection->execute(
            'UPDATE staff_members SET status = \'active\' WHERE id = :staff AND status = \'invited\'',
            ['staff' => $staffId],
        );
    }

    public function activePasskeyByCredential(string $credentialLookup): ?StoredPasskey
    {
        $rows = $this->repository->fetchAll(
            'SELECT p.id, p.staff_id, p.credential_id, p.public_key, p.algorithm, p.sign_count, p.aaguid, p.transports,
                    p.backup_eligible, p.backup_state, m.webauthn_user_handle
             FROM staff_passkeys p JOIN staff_members m ON m.id = p.staff_id
             WHERE p.credential_lookup = :lookup AND p.disabled_at IS NULL AND m.status = \'active\'',
            ['lookup' => $credentialLookup],
        );
        if ($rows === []) {
            return null;
        }
        $row = $rows[0];

        return new StoredPasskey(
            Row::string($row, 'id'),
            Row::string($row, 'staff_id'),
            Row::string($row, 'credential_id'),
            Row::string($row, 'public_key'),
            Row::int($row, 'algorithm'),
            Row::int($row, 'sign_count'),
            Row::string($row, 'aaguid'),
            Row::string($row, 'transports'),
            Row::int($row, 'backup_eligible') === 1,
            Row::int($row, 'backup_state') === 1,
            Row::string($row, 'webauthn_user_handle'),
        );
    }

    public function recordUse(string $passkeyId, int $signCount, bool $backupState, \DateTimeImmutable $now): void
    {
        $this->connection->execute(
            'UPDATE staff_passkeys SET sign_count = :count, backup_state = :backup, last_used_at = :now WHERE id = :id AND disabled_at IS NULL',
            ['count' => $signCount, 'backup' => $backupState ? 1 : 0, 'now' => self::time($now), 'id' => $passkeyId],
        );
    }

    public function disablePasskey(string $passkeyId, string $reason, \DateTimeImmutable $now): void
    {
        $this->connection->execute(
            'UPDATE staff_passkeys SET disabled_at = :now, disabled_reason = :reason WHERE id = :id AND disabled_at IS NULL',
            ['now' => self::time($now), 'reason' => $reason, 'id' => $passkeyId],
        );
    }

    private static function time(\DateTimeImmutable $now): string
    {
        return $now->setTimezone(new \DateTimeZone('UTC'))->format(self::TIME);
    }
}
