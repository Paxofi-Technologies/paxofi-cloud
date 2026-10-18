<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Domain\Staff;

use PaxofiCloud\Domain\Staff\Permission;
use PaxofiCloud\Domain\Staff\StaffRole;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The SRS v2.0 §2.2 staff permission matrix, transcribed cell by cell
 * (ACC-005; threat model T-32). If a role's permissions change, this table and
 * the SRS change together, through an SRS change record.
 */
final class StaffRoleMatrixTest extends TestCase
{
    private const array COLUMNS = ['support', 'billing', 'provisioning_operator', 'abuse', 'catalogue', 'finance', 'security_admin', 'super_admin'];

    /** Capability => cells in COLUMNS order, exactly as in SRS §2.2 ('—' = no access). */
    private const array SRS_2_2 = [
        'View customer 360' => ['R', 'R', 'R', 'R', '—', 'R', '—', 'R'],
        'View-as customer (read-only)' => ['W', '—', 'W', 'W', '—', '—', '—', 'W'],
        'Reply to tickets' => ['W', 'W', 'W', 'W', '—', '—', '—', 'W'],
        'Issue credit note / refund ≤ threshold' => ['—', 'W', '—', '—', '—', '—', '—', 'W'],
        'Approve refund > threshold' => ['—', 'A', '—', '—', '—', '—', '—', 'A'],
        'Retry/skip/cancel provisioning job' => ['—', '—', 'W', '—', '—', '—', '—', 'W'],
        'Suspend / unsuspend service' => ['—', 'W (billing)', 'W', 'W (abuse)', '—', '—', '—', 'W'],
        'Terminate service' => ['—', '—', '—', 'W', '—', '—', '—', 'W'],
        'Manage catalogue & prices' => ['—', '—', '—', '—', 'W', 'R', '—', 'W'],
        'Reports & ledger export' => ['—', 'R', 'R', '—', 'R', 'R', '—', 'R'],
        'Manage staff & roles' => ['—', '—', '—', '—', '—', '—', 'W', 'W'],
        'Manage provider credentials' => ['—', '—', '—', '—', '—', '—', 'W', 'W'],
        'Business settings (VAT, grace days)' => ['—', '—', '—', '—', '—', '—', '—', 'W'],
        'View audit log' => ['R (own actions)', 'R', 'R', 'R', 'R', 'R', 'R', 'R'],
    ];

    /** @return iterable<string, array{StaffRole}> */
    public static function roles(): iterable
    {
        foreach (StaffRole::cases() as $role) {
            yield $role->value => [$role];
        }
    }

    #[DataProvider('roles')]
    public function testRolePermissionsMatchTheSrsMatrix(StaffRole $role): void
    {
        $column = array_search($role->value, self::COLUMNS, true);
        self::assertIsInt($column, 'every role is a column of SRS §2.2');

        $expected = [];
        foreach (self::SRS_2_2 as $capability => $cells) {
            foreach (self::permissionsFor($capability, $cells[$column], $role) as $permission) {
                $expected[] = $permission->value;
            }
        }
        $actual = array_map(static fn (Permission $p): string => $p->value, $role->permissions());
        sort($expected);
        sort($actual);

        self::assertSame($expected, $actual);
    }

    public function testEveryRoleIsAColumnAndEveryPermissionIsUsed(): void
    {
        self::assertSame(self::COLUMNS, array_map(static fn (StaffRole $r): string => $r->value, StaffRole::cases()));
        $used = [];
        foreach (StaffRole::cases() as $role) {
            self::assertNotSame([], $role->permissions());
            self::assertSame(count($role->permissions()), count(array_unique(array_map(static fn (Permission $p): string => $p->value, $role->permissions()))));
            foreach ($role->permissions() as $permission) {
                $used[$permission->value] = true;
            }
        }
        self::assertCount(count(Permission::cases()), $used);
    }

    public function testThereIsNoUnrestrictedAdminPermission(): void
    {
        foreach (Permission::cases() as $permission) {
            self::assertMatchesRegularExpression('/^[a-z_]+(\.[a-z_]+)+$/', $permission->value);
            self::assertStringNotContainsString('*', $permission->value);
            self::assertStringNotContainsString('admin', $permission->value);
        }
    }

    public function testOnlySuperAdminGrantsNeedASecondApprover(): void
    {
        foreach (StaffRole::cases() as $role) {
            self::assertSame($role === StaffRole::SuperAdmin, $role->needsSecondApprover());
        }
    }

    /** @return list<Permission> */
    private static function permissionsFor(string $capability, string $cell, StaffRole $role): array
    {
        if ($cell === '—') {
            return [];
        }

        return match ($capability) {
            'View customer 360' => [Permission::CustomerView],
            'View-as customer (read-only)' => [Permission::CustomerViewAs],
            'Reply to tickets' => [Permission::TicketReply],
            'Issue credit note / refund ≤ threshold' => [Permission::RefundIssue],
            'Approve refund > threshold' => [Permission::RefundApprove],
            'Retry/skip/cancel provisioning job' => [Permission::ProvisioningJobManage],
            // A plain "W" is Provisioning's operational suspension; Super Admin holds all three.
            'Suspend / unsuspend service' => match (true) {
                $cell === 'W (billing)' => [Permission::ServiceSuspendBilling],
                $cell === 'W (abuse)' => [Permission::ServiceSuspendAbuse],
                $role === StaffRole::SuperAdmin => [Permission::ServiceSuspendBilling, Permission::ServiceSuspendOperations, Permission::ServiceSuspendAbuse],
                default => [Permission::ServiceSuspendOperations],
            },
            'Terminate service' => [Permission::ServiceTerminate],
            'Manage catalogue & prices' => $cell === 'W' ? [Permission::CatalogueManage, Permission::CatalogueView] : [Permission::CatalogueView],
            'Reports & ledger export' => [Permission::ReportsView],
            'Manage staff & roles' => [Permission::StaffManage],
            'Manage provider credentials' => [Permission::ProviderCredentialsManage],
            'Business settings (VAT, grace days)' => [Permission::SettingsManage],
            'View audit log' => $cell === 'R (own actions)' ? [Permission::AuditViewOwn] : [Permission::AuditView],
            default => throw new \LogicException('Unmapped capability: ' . $capability),
        };
    }
}
