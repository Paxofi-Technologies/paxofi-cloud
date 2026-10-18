<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Staff;

use Paxofi\Core\Contracts\TransactionManager;
use PaxofiCloud\Application\Audit\AuditEvent;
use PaxofiCloud\Application\Audit\AuditEventType;
use PaxofiCloud\Application\Audit\AuditTrail;
use PaxofiCloud\Application\Audit\ClientContext;
use PaxofiCloud\Application\Contracts\Clock;
use PaxofiCloud\Application\Contracts\StaffDirectory;
use PaxofiCloud\Domain\Staff\Permission;
use PaxofiCloud\Domain\Staff\StaffRole;

/**
 * Granting, approving and revoking staff roles (SRS ACC-005, SEC-017; threat
 * model §6.16, T-33). Needs `staff.manage`. Nobody grants a role to
 * themselves; a Super Admin grant waits for a second person who is neither the
 * requester nor the grantee; the last staff manager cannot be removed, so
 * staff management can never lock itself out. Every outcome is audited.
 */
final readonly class StaffRoles
{
    public const string ACTIVE = 'active';
    public const string PENDING = 'pending';

    public function __construct(
        private StaffDirectory $directory,
        private StaffAuthorizer $authorizer,
        private TransactionManager $transactions,
        private Clock $clock,
        private AuditTrail $audit,
    ) {
    }

    /**
     * @return 'active'|'pending' pending when the role needs a second approver
     * @throws StaffPermissionDenied|StaffRoleChangeRefused
     */
    public function grant(string $actorId, string $staffId, StaffRole $role, ClientContext $client): string
    {
        $this->authorizer->authorize($actorId, Permission::StaffManage, $client);
        $this->assertId($staffId, ['actor' => $actorId, 'role' => $role->value], $client);
        $details = ['actor' => $actorId, 'subject' => $staffId, 'role' => $role->value];
        $status = $role->needsSecondApprover() ? self::PENDING : self::ACTIVE;

        $this->guarded($details, $client, function () use ($actorId, $staffId, $role, $status): void {
            if ($actorId === $staffId) {
                throw new StaffRoleChangeRefused(StaffRoleChangeRefused::SELF_GRANT);
            }
            $target = $this->directory->status($staffId);
            if ($target === null || $target === 'disabled') {
                throw new StaffRoleChangeRefused(StaffRoleChangeRefused::UNKNOWN_STAFF);
            }
            if ($this->directory->hasOpenGrant($staffId, $role)) {
                throw new StaffRoleChangeRefused(StaffRoleChangeRefused::ALREADY_GRANTED);
            }
            $this->directory->addGrant(bin2hex(random_bytes(16)), $staffId, $role, $status, $actorId, $this->clock->now());
        });

        $type = $status === self::PENDING ? AuditEventType::STAFF_ROLE_REQUESTED : AuditEventType::STAFF_ROLE_GRANTED;
        $this->audit->record($type, AuditEvent::SUCCESS, $client, details: $details);

        return $status;
    }

    /**
     * The second approval of a pending Super Admin grant (SEC-017).
     *
     * @throws StaffPermissionDenied|StaffRoleChangeRefused
     */
    public function approve(string $actorId, string $grantId, ClientContext $client): void
    {
        $this->authorizer->authorize($actorId, Permission::StaffManage, $client);
        $details = ['actor' => $actorId];
        $this->assertId($grantId, $details, $client, StaffRoleChangeRefused::NOT_PENDING);

        $grant = $this->guarded($details, $client, function () use ($actorId, $grantId): array {
            $grant = $this->directory->pendingGrantForUpdate($grantId);
            if ($grant === null) {
                throw new StaffRoleChangeRefused(StaffRoleChangeRefused::NOT_PENDING);
            }
            if ($actorId === $grant['staff_id'] || $actorId === $grant['granted_by']) {
                throw new StaffRoleChangeRefused(StaffRoleChangeRefused::SAME_PERSON);
            }
            if ($this->directory->status($grant['staff_id']) === 'disabled'
                || !$this->directory->approveGrant($grantId, $actorId, $this->clock->now())) {
                throw new StaffRoleChangeRefused(StaffRoleChangeRefused::NOT_PENDING);
            }

            return $grant;
        });

        $this->audit->record(AuditEventType::STAFF_ROLE_APPROVED, AuditEvent::SUCCESS, $client, details: [
            'actor' => $actorId,
            'subject' => $grant['staff_id'],
            'role' => $grant['role']->value,
        ]);
    }

    /** @throws StaffPermissionDenied|StaffRoleChangeRefused */
    public function revoke(string $actorId, string $staffId, StaffRole $role, ClientContext $client): void
    {
        $this->authorizer->authorize($actorId, Permission::StaffManage, $client);
        $this->assertId($staffId, ['actor' => $actorId, 'role' => $role->value], $client, StaffRoleChangeRefused::NOT_GRANTED);
        $details = ['actor' => $actorId, 'subject' => $staffId, 'role' => $role->value];

        $this->guarded($details, $client, function () use ($actorId, $staffId, $role): void {
            if (!$this->directory->revokeGrant($staffId, $role, $actorId, $this->clock->now())) {
                throw new StaffRoleChangeRefused(StaffRoleChangeRefused::NOT_GRANTED);
            }
            // Counted after the revocation, inside the transaction: refusing rolls it back.
            if (in_array(Permission::StaffManage, $role->permissions(), true)
                && $this->directory->countActiveHoldersForUpdate(self::managerRoles()) === 0) {
                throw new StaffRoleChangeRefused(StaffRoleChangeRefused::LAST_ADMIN);
            }
        });

        $this->audit->record(AuditEventType::STAFF_ROLE_REVOKED, AuditEvent::SUCCESS, $client, details: $details);
    }

    /**
     * IDs from a request are checked before they reach storage or the audit
     * log, which accepts fixed codes only.
     *
     * @param array<string, string> $details
     * @throws StaffRoleChangeRefused
     */
    private function assertId(string $id, array $details, ClientContext $client, string $reason = StaffRoleChangeRefused::UNKNOWN_STAFF): void
    {
        if (preg_match('/^[a-f0-9]{32}$/', $id) !== 1) {
            $this->audit->record(AuditEventType::STAFF_ROLE_CHANGE_REFUSED, AuditEvent::FAILURE, $client, details: $details + ['reason' => $reason]);

            throw new StaffRoleChangeRefused($reason);
        }
    }

    /** @return list<StaffRole> */
    private static function managerRoles(): array
    {
        return array_values(array_filter(
            StaffRole::cases(),
            static fn (StaffRole $r): bool => in_array(Permission::StaffManage, $r->permissions(), true),
        ));
    }

    /**
     * Runs the change in a transaction and audits a refusal with its reason.
     *
     * @template T
     * @param array<string, string> $details
     * @param callable(): T $change
     * @return T
     */
    private function guarded(array $details, ClientContext $client, callable $change): mixed
    {
        try {
            return $this->transactions->transaction($change);
        } catch (StaffRoleChangeRefused $e) {
            $this->audit->record(AuditEventType::STAFF_ROLE_CHANGE_REFUSED, AuditEvent::FAILURE, $client, details: $details + ['reason' => $e->reason]);

            throw $e;
        }
    }
}
