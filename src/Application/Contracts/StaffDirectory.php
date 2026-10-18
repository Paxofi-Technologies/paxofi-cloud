<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Contracts;

use PaxofiCloud\Domain\Staff\StaffRole;

/** Staff members and role grants (migration 0007; threat model §6.16). */
interface StaffDirectory
{
    /** @return 'invited'|'active'|'disabled'|null null when there is no such staff member */
    public function status(string $staffId): ?string;

    /** @return list<StaffRole> roles whose grant is active now */
    public function activeRoles(string $staffId): array;

    public function hasOpenGrant(string $staffId, StaffRole $role): bool;

    /** @param 'pending'|'active' $status */
    public function addGrant(string $grantId, string $staffId, StaffRole $role, string $status, ?string $grantedBy, \DateTimeImmutable $now): void;

    /**
     * Locks a pending grant for approval.
     *
     * @return array{staff_id: string, role: StaffRole, granted_by: ?string}|null
     */
    public function pendingGrantForUpdate(string $grantId): ?array;

    /** @return bool false when the grant was no longer pending */
    public function approveGrant(string $grantId, string $approvedBy, \DateTimeImmutable $now): bool;

    /** Revokes the open (pending or active) grant. @return bool false when there was none */
    public function revokeGrant(string $staffId, StaffRole $role, string $revokedBy, \DateTimeImmutable $now): bool;

    /**
     * Active staff members who hold at least one of the roles, locking their
     * grants so two concurrent revocations cannot both pass a count check.
     *
     * @param list<StaffRole> $roles
     */
    public function countActiveHoldersForUpdate(array $roles): int;
}
