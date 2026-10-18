<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Staff;

use PaxofiCloud\Application\Audit\AuditEvent;
use PaxofiCloud\Application\Audit\AuditEventType;
use PaxofiCloud\Application\Audit\AuditTrail;
use PaxofiCloud\Application\Audit\ClientContext;
use PaxofiCloud\Application\Contracts\StaffDirectory;
use PaxofiCloud\Domain\Staff\Permission;

/**
 * Deny-by-default permission check for every staff action (SRS ACC-005;
 * threat model §6.16, T-32). Roles are read from the directory on every call,
 * never from the session, so a revocation applies at the next request. Every
 * refusal is audited with the permission that was asked for.
 */
final readonly class StaffAuthorizer
{
    public function __construct(private StaffDirectory $directory, private AuditTrail $audit)
    {
    }

    /** @throws StaffPermissionDenied */
    public function authorize(string $staffId, Permission $permission, ClientContext $client): StaffPrincipal
    {
        $principal = $this->directory->status($staffId) === 'active'
            ? new StaffPrincipal($staffId, $this->directory->activeRoles($staffId))
            : null;
        if ($principal === null || !$principal->can($permission)) {
            $details = ['permission' => $permission->value];
            if (preg_match('/^[a-f0-9]{32}$/', $staffId) === 1) {
                $details['actor'] = $staffId;
            }
            $this->audit->record(AuditEventType::STAFF_PERMISSION_DENIED, AuditEvent::FAILURE, $client, details: $details);

            throw new StaffPermissionDenied($permission);
        }

        return $principal;
    }
}
