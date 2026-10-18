<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Staff;

use Paxofi\Core\Contracts\Connection;
use Paxofi\Core\Contracts\Repository;
use PaxofiCloud\Application\Contracts\StaffDirectory;
use PaxofiCloud\Domain\Staff\StaffRole;
use PaxofiCloud\Infrastructure\Persistence\Row;

/** MySQL storage for staff members and role grants (migration 0007). */
final readonly class MysqlStaffDirectory implements StaffDirectory
{
    private const string TIME = 'Y-m-d H:i:s.u';

    public function __construct(private Connection $connection, private Repository $repository)
    {
    }

    public function status(string $staffId): ?string
    {
        $rows = $this->repository->fetchAll('SELECT status FROM staff_members WHERE id = :id', ['id' => $staffId]);
        if ($rows === []) {
            return null;
        }
        $status = Row::string($rows[0], 'status');

        return match ($status) {
            'invited', 'active', 'disabled' => $status,
            default => throw new \UnexpectedValueException('Unknown staff status.'),
        };
    }

    public function activeRoles(string $staffId): array
    {
        $rows = $this->repository->fetchAll(
            'SELECT role FROM staff_role_assignments WHERE staff_id = :id AND status = \'active\' ORDER BY role',
            ['id' => $staffId],
        );

        return array_map(static fn (array $row): StaffRole => StaffRole::from(Row::string($row, 'role')), $rows);
    }

    public function hasOpenGrant(string $staffId, StaffRole $role): bool
    {
        return $this->repository->fetchAll(
            'SELECT id FROM staff_role_assignments WHERE staff_id = :id AND role = :role AND status IN (\'pending\', \'active\') FOR UPDATE',
            ['id' => $staffId, 'role' => $role->value],
        ) !== [];
    }

    public function addGrant(string $grantId, string $staffId, StaffRole $role, string $status, ?string $grantedBy, \DateTimeImmutable $now): void
    {
        $time = self::time($now);
        $this->connection->execute(
            'INSERT INTO staff_role_assignments (id, staff_id, role, status, granted_by, requested_at, activated_at)
             VALUES (:id, :staff, :role, :status, :granted_by, :requested_at, :activated_at)',
            [
                'id' => $grantId,
                'staff' => $staffId,
                'role' => $role->value,
                'status' => $status,
                'granted_by' => $grantedBy,
                'requested_at' => $time,
                'activated_at' => $status === 'active' ? $time : null,
            ],
        );
    }

    public function pendingGrantForUpdate(string $grantId): ?array
    {
        $rows = $this->repository->fetchAll(
            'SELECT staff_id, role, granted_by FROM staff_role_assignments WHERE id = :id AND status = \'pending\' FOR UPDATE',
            ['id' => $grantId],
        );
        if ($rows === []) {
            return null;
        }
        $grantedBy = $rows[0]['granted_by'] ?? null;

        return [
            'staff_id' => Row::string($rows[0], 'staff_id'),
            'role' => StaffRole::from(Row::string($rows[0], 'role')),
            'granted_by' => is_string($grantedBy) ? $grantedBy : null,
        ];
    }

    public function approveGrant(string $grantId, string $approvedBy, \DateTimeImmutable $now): bool
    {
        return $this->connection->execute(
            'UPDATE staff_role_assignments SET status = \'active\', approved_by = :approved_by, activated_at = :now
             WHERE id = :id AND status = \'pending\'',
            ['id' => $grantId, 'approved_by' => $approvedBy, 'now' => self::time($now)],
        ) === 1;
    }

    public function revokeGrant(string $staffId, StaffRole $role, string $revokedBy, \DateTimeImmutable $now): bool
    {
        return $this->connection->execute(
            'UPDATE staff_role_assignments SET status = \'revoked\', revoked_by = :revoked_by, revoked_at = :now
             WHERE staff_id = :staff AND role = :role AND status IN (\'pending\', \'active\')',
            ['staff' => $staffId, 'role' => $role->value, 'revoked_by' => $revokedBy, 'now' => self::time($now)],
        ) === 1;
    }

    public function countActiveHoldersForUpdate(array $roles): int
    {
        $holders = [];
        foreach ($roles as $role) {
            $rows = $this->repository->fetchAll(
                'SELECT a.staff_id FROM staff_role_assignments a
                 JOIN staff_members m ON m.id = a.staff_id AND m.status = \'active\'
                 WHERE a.status = \'active\' AND a.role = :role FOR UPDATE',
                ['role' => $role->value],
            );
            foreach ($rows as $row) {
                $holders[Row::string($row, 'staff_id')] = true;
            }
        }

        return count($holders);
    }

    private static function time(\DateTimeImmutable $now): string
    {
        return $now->setTimezone(new \DateTimeZone('UTC'))->format(self::TIME);
    }
}
