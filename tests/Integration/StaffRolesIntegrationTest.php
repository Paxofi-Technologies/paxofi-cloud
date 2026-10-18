<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Integration;

use Paxofi\Core\Contracts\Repository;
use PaxofiCloud\Application\Audit\AuditEventType;
use PaxofiCloud\Application\Staff\StaffAuthorizer;
use PaxofiCloud\Application\Staff\StaffPermissionDenied;
use PaxofiCloud\Application\Staff\StaffRoleChangeRefused;
use PaxofiCloud\Application\Staff\StaffRoles;
use PaxofiCloud\Bootstrap\AppFactory;
use PaxofiCloud\Domain\Staff\Permission;
use PaxofiCloud\Domain\Staff\StaffRole;
use PaxofiCloud\Infrastructure\Container\Resolve;
use PaxofiCloud\Infrastructure\Migrations\MigrationLoader;
use PaxofiCloud\Infrastructure\Migrations\Migrator;
use PaxofiCloud\Infrastructure\Migrations\MysqlAdvisoryLock;
use Paxofi\Core\Contracts\Connection;
use PaxofiCloud\Tests\Support\AuditFixtures;
use PDO;

/**
 * Staff roles on MySQL 8.4 (migration 0007; SRS ACC-005, SEC-017; threat
 * model §6.16): the database refuses what the application refuses, so a bug
 * or a hand-written UPDATE cannot activate a Super Admin alone.
 */
final class StaffRolesIntegrationTest extends IntegrationTestCase
{
    private const string ADMIN = 'a0000000000000000000000000000000';
    private const string ADMIN2 = 'a2000000000000000000000000000000';
    private const string OTHER = 'c0000000000000000000000000000000';

    private PDO $pdo;
    private StaffRoles $roles;
    private StaffAuthorizer $authorizer;

    protected function setUp(): void
    {
        parent::setUp();
        self::resetDatabase();
        $app = AppFactory::create(self::basePath());
        $repository = Resolve::get($app->container(), Repository::class);
        (new Migrator(Resolve::get($app->container(), Connection::class), $repository, new MysqlAdvisoryLock($repository)))
            ->migrate((new MigrationLoader())->load(self::basePath() . '/migrations'));
        $this->roles = Resolve::get($app->container(), StaffRoles::class);
        $this->authorizer = Resolve::get($app->container(), StaffAuthorizer::class);

        $this->pdo = self::pdo();
        $insert = $this->pdo->prepare('INSERT INTO staff_members (id, email, email_lookup, full_name, status, created_at) VALUES (?, ?, ?, ?, \'active\', NOW(6))');
        foreach ([self::ADMIN => 'staff0@example.test', self::ADMIN2 => 'staff1@example.test', self::OTHER => 'staff2@example.test'] as $id => $email) {
            $insert->execute([$id, $email, hash('sha256', $email), 'Staff member']);
        }
        // The operator bootstrap grant (granted_by NULL), as the CLI in the next PR will write it.
        $this->insertGrant('10000000000000000000000000000000', self::ADMIN, 'security_admin', 'active', null);
    }

    public function testGrantApproveAndRevokeThroughMysql(): void
    {
        self::assertSame(StaffRoles::ACTIVE, $this->roles->grant(self::ADMIN, self::ADMIN2, StaffRole::SecurityAdmin, AuditFixtures::client()));
        self::assertSame(StaffRoles::PENDING, $this->roles->grant(self::ADMIN, self::OTHER, StaffRole::SuperAdmin, AuditFixtures::client()));
        $this->expectDenied(Permission::SettingsManage);

        $grant = $this->scalar('SELECT id FROM staff_role_assignments WHERE staff_id = ? AND role = \'super_admin\'', [self::OTHER]);
        $this->roles->approve(self::ADMIN2, $grant, AuditFixtures::client());
        $this->authorizer->authorize(self::OTHER, Permission::SettingsManage, AuditFixtures::client());

        $this->roles->revoke(self::ADMIN2, self::OTHER, StaffRole::SuperAdmin, AuditFixtures::client());
        $this->expectDenied(Permission::SettingsManage);

        $events = $this->pdo->query('SELECT event_type FROM audit_events ORDER BY seq');
        self::assertInstanceOf(\PDOStatement::class, $events);
        self::assertSame(
            [AuditEventType::STAFF_ROLE_GRANTED, AuditEventType::STAFF_ROLE_REQUESTED, AuditEventType::STAFF_PERMISSION_DENIED, AuditEventType::STAFF_ROLE_APPROVED, AuditEventType::STAFF_ROLE_REVOKED, AuditEventType::STAFF_PERMISSION_DENIED],
            $events->fetchAll(PDO::FETCH_COLUMN),
        );
    }

    public function testTheLastStaffManagerRevocationIsRolledBack(): void
    {
        try {
            $this->roles->revoke(self::ADMIN, self::ADMIN, StaffRole::SecurityAdmin, AuditFixtures::client());
            self::fail('The last staff manager must not be removable.');
        } catch (StaffRoleChangeRefused $e) {
            self::assertSame(StaffRoleChangeRefused::LAST_ADMIN, $e->reason);
        }
        self::assertSame('active', $this->scalar('SELECT status FROM staff_role_assignments WHERE staff_id = ?', [self::ADMIN]));
    }

    public function testDatabaseRefusesASuperAdminActivatedWithoutASecondPerson(): void
    {
        // No approver at all.
        $this->assertRefusedByDatabase(fn () => $this->insertGrant('20000000000000000000000000000000', self::OTHER, 'super_admin', 'active', self::ADMIN));
        // Approved by the requester.
        $this->assertRefusedByDatabase(fn () => $this->insertGrant('21000000000000000000000000000000', self::OTHER, 'super_admin', 'active', self::ADMIN, self::ADMIN));
        // Approved by the grantee.
        $this->assertRefusedByDatabase(fn () => $this->insertGrant('22000000000000000000000000000000', self::OTHER, 'super_admin', 'active', self::ADMIN, self::OTHER));
        // Bootstrapped without a requester.
        $this->assertRefusedByDatabase(fn () => $this->insertGrant('23000000000000000000000000000000', self::OTHER, 'super_admin', 'active', null, self::ADMIN2));
        // A pending grant flipped to active by hand.
        $this->insertGrant('24000000000000000000000000000000', self::OTHER, 'super_admin', 'pending', self::ADMIN);
        $this->assertRefusedByDatabase(fn () => $this->pdo->exec('UPDATE staff_role_assignments SET status = \'active\', activated_at = NOW(6) WHERE id = \'24000000000000000000000000000000\''));

        // The valid shape is accepted.
        $this->pdo->prepare('UPDATE staff_role_assignments SET status = \'active\', activated_at = NOW(6), approved_by = ? WHERE id = ?')
            ->execute([self::ADMIN2, '24000000000000000000000000000000']);
        self::assertSame('active', $this->scalar('SELECT status FROM staff_role_assignments WHERE id = ?', ['24000000000000000000000000000000']));
    }

    public function testDatabaseRefusesSelfGrantsDuplicatesAndUnknownRoles(): void
    {
        $this->assertRefusedByDatabase(fn () => $this->insertGrant('30000000000000000000000000000000', self::OTHER, 'billing', 'active', self::OTHER));
        $this->assertRefusedByDatabase(fn () => $this->insertGrant('31000000000000000000000000000000', self::OTHER, 'admin', 'active', self::ADMIN));
        $this->assertRefusedByDatabase(fn () => $this->insertGrant('32000000000000000000000000000000', self::OTHER, 'billing', 'active', self::ADMIN, self::ADMIN2));

        $this->insertGrant('33000000000000000000000000000000', self::OTHER, 'billing', 'active', self::ADMIN);
        $this->assertRefusedByDatabase(fn () => $this->insertGrant('34000000000000000000000000000000', self::OTHER, 'billing', 'pending', self::ADMIN));

        // A revoked grant does not block a new one.
        $this->pdo->exec('UPDATE staff_role_assignments SET status = \'revoked\' WHERE id = \'33000000000000000000000000000000\'');
        $this->insertGrant('35000000000000000000000000000000', self::OTHER, 'billing', 'active', self::ADMIN);
        self::assertSame('2', $this->scalar('SELECT COUNT(*) FROM staff_role_assignments WHERE staff_id = ? AND role = \'billing\'', [self::OTHER]));
    }

    private function expectDenied(Permission $permission): void
    {
        try {
            $this->authorizer->authorize(self::OTHER, $permission, AuditFixtures::client());
            self::fail('Expected a permission denial.');
        } catch (StaffPermissionDenied) {
        }
    }

    private function insertGrant(string $id, string $staffId, string $role, string $status, ?string $grantedBy, ?string $approvedBy = null): void
    {
        $this->pdo->prepare(
            'INSERT INTO staff_role_assignments (id, staff_id, role, status, granted_by, approved_by, requested_at, activated_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW(6), IF(? = \'active\', NOW(6), NULL))',
        )->execute([$id, $staffId, $role, $status, $grantedBy, $approvedBy, $status]);
    }

    /** @param list<string> $parameters */
    private function scalar(string $sql, array $parameters): string
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        $value = $statement->fetchColumn();
        self::assertTrue(is_string($value) || is_int($value));

        return (string) $value;
    }

    /** @param callable(): mixed $write */
    private function assertRefusedByDatabase(callable $write): void
    {
        try {
            $write();
            self::fail('The database must refuse this row.');
        } catch (\PDOException $e) {
            // 3819 CHECK constraint violated; 1062 duplicate open grant.
            $code = $e->errorInfo[1] ?? null;
            self::assertContains(is_int($code) ? $code : 0, [3819, 1062], $e->getMessage());
        }
    }
}
