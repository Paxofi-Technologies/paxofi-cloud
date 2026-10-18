<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Support;

use Paxofi\Core\Contracts\TransactionManager;
use PaxofiCloud\Application\Contracts\StaffDirectory;
use PaxofiCloud\Domain\Staff\StaffRole;

/** Staff directory in memory; also a transaction manager that rolls its own state back on failure. */
final class InMemoryStaffDirectory implements StaffDirectory, TransactionManager
{
    /** @var array<string, 'invited'|'active'|'disabled'> */
    public array $members = [];

    /** @var array<string, array{staff_id: string, role: StaffRole, status: string, granted_by: ?string, approved_by: ?string}> */
    public array $grants = [];

    /** @var array<string, array{email: string, full_name: string}> */
    public array $profiles = [];

    public function add(string $staffId, string $status = 'active', StaffRole ...$roles): void
    {
        $this->members[$staffId] = match ($status) {
            'invited', 'active', 'disabled' => $status,
            default => throw new \InvalidArgumentException('status'),
        };
        foreach ($roles as $role) {
            $this->grants[bin2hex(random_bytes(16))] = ['staff_id' => $staffId, 'role' => $role, 'status' => 'active', 'granted_by' => null, 'approved_by' => null];
        }
    }

    public function transaction(callable $operation): mixed
    {
        $members = $this->members;
        $grants = $this->grants;
        try {
            return $operation();
        } catch (\Throwable $e) {
            $this->members = $members;
            $this->grants = $grants;

            throw $e;
        }
    }

    public function status(string $staffId): ?string
    {
        return $this->members[$staffId] ?? null;
    }

    public function activeRoles(string $staffId): array
    {
        $roles = [];
        foreach ($this->grants as $grant) {
            if ($grant['staff_id'] === $staffId && $grant['status'] === 'active') {
                $roles[] = $grant['role'];
            }
        }

        return $roles;
    }

    public function hasOpenGrant(string $staffId, StaffRole $role): bool
    {
        return $this->openGrantId($staffId, $role) !== null;
    }

    public function addGrant(string $grantId, string $staffId, StaffRole $role, string $status, ?string $grantedBy, \DateTimeImmutable $now): void
    {
        $this->grants[$grantId] = ['staff_id' => $staffId, 'role' => $role, 'status' => $status, 'granted_by' => $grantedBy, 'approved_by' => null];
    }

    public function pendingGrantForUpdate(string $grantId): ?array
    {
        $grant = $this->grants[$grantId] ?? null;
        if ($grant === null || $grant['status'] !== 'pending') {
            return null;
        }

        return ['staff_id' => $grant['staff_id'], 'role' => $grant['role'], 'granted_by' => $grant['granted_by']];
    }

    public function approveGrant(string $grantId, string $approvedBy, \DateTimeImmutable $now): bool
    {
        if (($this->grants[$grantId]['status'] ?? null) !== 'pending') {
            return false;
        }
        $this->grants[$grantId]['status'] = 'active';
        $this->grants[$grantId]['approved_by'] = $approvedBy;

        return true;
    }

    public function revokeGrant(string $staffId, StaffRole $role, string $revokedBy, \DateTimeImmutable $now): bool
    {
        $id = $this->openGrantId($staffId, $role);
        if ($id === null) {
            return false;
        }
        $this->grants[$id]['status'] = 'revoked';

        return true;
    }

    public function countActiveHoldersForUpdate(array $roles): int
    {
        $holders = [];
        foreach ($this->grants as $grant) {
            if ($grant['status'] === 'active' && in_array($grant['role'], $roles, true) && ($this->members[$grant['staff_id']] ?? null) === 'active') {
                $holders[$grant['staff_id']] = true;
            }
        }

        return count($holders);
    }

    /** The ID of the open grant, for approving it in tests. */
    public function openGrantId(string $staffId, StaffRole $role): ?string
    {
        foreach ($this->grants as $id => $grant) {
            if ($grant['staff_id'] === $staffId && $grant['role'] === $role && in_array($grant['status'], ['pending', 'active'], true)) {
                return $id;
            }
        }

        return null;
    }

    public function members(): array
    {
        $members = [];
        foreach ($this->members as $id => $status) {
            $members[] = [
                'id' => $id,
                'email' => $this->profiles[$id]['email'] ?? '',
                'full_name' => $this->profiles[$id]['full_name'] ?? '',
                'status' => $status,
                'roles' => $this->activeRoles($id),
            ];
        }

        return $members;
    }

    public function pendingGrants(): array
    {
        $pending = [];
        foreach ($this->grants as $id => $grant) {
            if ($grant['status'] === 'pending') {
                $pending[] = ['grant_id' => $id, 'staff_id' => $grant['staff_id'], 'role' => $grant['role'], 'granted_by' => $grant['granted_by']];
            }
        }

        return $pending;
    }

    public function activeHoldersOf(array $roles): array
    {
        $holders = [];
        foreach ($this->grants as $grant) {
            $id = $grant['staff_id'];
            if ($grant['status'] === 'active' && in_array($grant['role'], $roles, true) && ($this->members[$id] ?? null) === 'active') {
                $holders[$id] = ['id' => $id, 'email' => $this->profiles[$id]['email'] ?? '', 'full_name' => $this->profiles[$id]['full_name'] ?? ''];
            }
        }

        return array_values($holders);
    }
}
