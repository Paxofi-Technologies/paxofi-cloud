<?php

declare(strict_types=1);

namespace PaxofiCloud\Domain\Staff;

/**
 * Staff roles and their fixed permission sets, transcribed from the SRS §2.2
 * matrix (ACC-005). Changing a set is a reviewed code change, and
 * StaffRoleMatrixTest holds the SRS table so the two cannot drift silently.
 */
enum StaffRole: string
{
    case Support = 'support';
    case Billing = 'billing';
    case ProvisioningOperator = 'provisioning_operator';
    case Abuse = 'abuse';
    case Catalogue = 'catalogue';
    case Finance = 'finance';
    case SecurityAdmin = 'security_admin';
    case SuperAdmin = 'super_admin';

    /** @return list<Permission> */
    public function permissions(): array
    {
        return match ($this) {
            self::Support => [
                Permission::CustomerView,
                Permission::CustomerViewAs,
                Permission::TicketReply,
                Permission::AuditViewOwn,
            ],
            self::Billing => [
                Permission::CustomerView,
                Permission::TicketReply,
                Permission::RefundIssue,
                Permission::RefundApprove,
                Permission::ServiceSuspendBilling,
                Permission::ReportsView,
                Permission::AuditView,
            ],
            self::ProvisioningOperator => [
                Permission::CustomerView,
                Permission::CustomerViewAs,
                Permission::TicketReply,
                Permission::ProvisioningJobManage,
                Permission::ServiceSuspendOperations,
                Permission::ReportsView,
                Permission::AuditView,
            ],
            self::Abuse => [
                Permission::CustomerView,
                Permission::CustomerViewAs,
                Permission::TicketReply,
                Permission::ServiceSuspendAbuse,
                Permission::ServiceTerminate,
                Permission::AuditView,
            ],
            self::Catalogue => [
                Permission::CatalogueManage,
                Permission::CatalogueView,
                Permission::ReportsView,
                Permission::AuditView,
            ],
            self::Finance => [
                Permission::CustomerView,
                Permission::CatalogueView,
                Permission::ReportsView,
                Permission::AuditView,
            ],
            self::SecurityAdmin => [
                Permission::StaffManage,
                Permission::ProviderCredentialsManage,
                Permission::AuditView,
            ],
            // Break-glass: every permission except the narrower "own actions" audit view.
            self::SuperAdmin => self::allExcept(Permission::AuditViewOwn),
        };
    }

    /** @return list<Permission> */
    private static function allExcept(Permission $excluded): array
    {
        $permissions = [];
        foreach (Permission::cases() as $permission) {
            if ($permission !== $excluded) {
                $permissions[] = $permission;
            }
        }

        return $permissions;
    }

    /** A grant of this role takes effect only after a second person approves it (SEC-017). */
    public function needsSecondApprover(): bool
    {
        return $this === self::SuperAdmin;
    }
}
