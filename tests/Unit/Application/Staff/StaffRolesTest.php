<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Application\Staff;

use PaxofiCloud\Application\Audit\AuditEventType;
use PaxofiCloud\Application\Staff\StaffAuthorizer;
use PaxofiCloud\Application\Staff\StaffPermissionDenied;
use PaxofiCloud\Application\Staff\StaffRoleChangeRefused;
use PaxofiCloud\Application\Staff\StaffRoles;
use PaxofiCloud\Domain\Staff\Permission;
use PaxofiCloud\Domain\Staff\StaffRole;
use PaxofiCloud\Tests\Support\AuditFixtures;
use PaxofiCloud\Tests\Support\FixedClock;
use PaxofiCloud\Tests\Support\InMemoryAuditLog;
use PaxofiCloud\Tests\Support\InMemoryStaffDirectory;
use PHPUnit\Framework\TestCase;

/** Granting, approving and revoking staff roles (SRS ACC-005, SEC-017; threat model §6.16, T-33). */
final class StaffRolesTest extends TestCase
{
    private const string ADMIN = 'a0000000000000000000000000000000';
    private const string ADMIN2 = 'a2000000000000000000000000000000';
    private const string AGENT = 'b0000000000000000000000000000000';
    private const string OTHER = 'c0000000000000000000000000000000';

    private InMemoryStaffDirectory $directory;
    private InMemoryAuditLog $audit;
    private StaffAuthorizer $authorizer;
    private StaffRoles $roles;

    protected function setUp(): void
    {
        $this->directory = new InMemoryStaffDirectory();
        $this->directory->add(self::ADMIN, 'active', StaffRole::SecurityAdmin);
        $this->directory->add(self::AGENT, 'active', StaffRole::Support);
        $this->directory->add(self::OTHER);
        $this->audit = new InMemoryAuditLog();
        $trail = AuditFixtures::trail($this->audit);
        $this->authorizer = new StaffAuthorizer($this->directory, $trail);
        $this->roles = new StaffRoles($this->directory, $this->authorizer, $this->directory, new FixedClock(new \DateTimeImmutable('2026-10-26 09:00:00 UTC')), $trail);
    }

    public function testSecurityAdminGrantsAnOrdinaryRoleImmediately(): void
    {
        self::assertSame(StaffRoles::ACTIVE, $this->roles->grant(self::ADMIN, self::OTHER, StaffRole::Billing, AuditFixtures::client()));

        $this->authorizer->authorize(self::OTHER, Permission::RefundIssue, AuditFixtures::client());
        self::assertSame([AuditEventType::STAFF_ROLE_GRANTED], $this->audit->types());
        self::assertSame(['actor' => self::ADMIN, 'subject' => self::OTHER, 'role' => 'billing'], $this->audit->events[0]->details);
    }

    public function testStaffWithoutStaffManageCannotGrant(): void
    {
        $this->expectException(StaffPermissionDenied::class);
        try {
            $this->roles->grant(self::AGENT, self::OTHER, StaffRole::Support, AuditFixtures::client());
        } finally {
            self::assertSame([], $this->directory->activeRoles(self::OTHER));
            self::assertSame([AuditEventType::STAFF_PERMISSION_DENIED], $this->audit->types());
        }
    }

    public function testNobodyGrantsARoleToThemselves(): void
    {
        $this->assertRefused(StaffRoleChangeRefused::SELF_GRANT, fn () => $this->roles->grant(self::ADMIN, self::ADMIN, StaffRole::Finance, AuditFixtures::client()));
        self::assertSame([StaffRole::SecurityAdmin], $this->directory->activeRoles(self::ADMIN));
    }

    public function testUnknownDisabledOrMalformedStaffCannotBeGranted(): void
    {
        $this->directory->add('d0000000000000000000000000000000', 'disabled');
        foreach (['d0000000000000000000000000000000', 'e0000000000000000000000000000000', 'x'] as $target) {
            $this->assertRefused(StaffRoleChangeRefused::UNKNOWN_STAFF, fn () => $this->roles->grant(self::ADMIN, $target, StaffRole::Support, AuditFixtures::client()));
        }
        self::assertArrayNotHasKey('subject', $this->audit->events[2]->details);
    }

    public function testAnOpenGrantIsNotDuplicated(): void
    {
        $this->assertRefused(StaffRoleChangeRefused::ALREADY_GRANTED, fn () => $this->roles->grant(self::ADMIN, self::AGENT, StaffRole::Support, AuditFixtures::client()));
    }

    /** SEC-017 acceptance: a Super Admin grant takes effect only after a second approver confirms. */
    public function testSuperAdminGrantWaitsForASecondPerson(): void
    {
        $this->directory->add(self::ADMIN2, 'active', StaffRole::SecurityAdmin);

        self::assertSame(StaffRoles::PENDING, $this->roles->grant(self::ADMIN, self::OTHER, StaffRole::SuperAdmin, AuditFixtures::client()));
        self::assertSame([], $this->directory->activeRoles(self::OTHER), 'pending grants give nothing');
        $grant = $this->directory->openGrantId(self::OTHER, StaffRole::SuperAdmin);
        self::assertIsString($grant);

        // Neither the requester nor the grantee can approve.
        $this->assertRefused(StaffRoleChangeRefused::SAME_PERSON, fn () => $this->roles->approve(self::ADMIN, $grant, AuditFixtures::client()));
        $this->expectDenied(fn () => $this->roles->approve(self::OTHER, $grant, AuditFixtures::client()));
        self::assertSame([], $this->directory->activeRoles(self::OTHER));

        $this->roles->approve(self::ADMIN2, $grant, AuditFixtures::client());

        self::assertSame([StaffRole::SuperAdmin], $this->directory->activeRoles(self::OTHER));
        self::assertSame(self::ADMIN2, $this->directory->grants[$grant]['approved_by']);
        self::assertSame(
            [AuditEventType::STAFF_ROLE_REQUESTED, AuditEventType::STAFF_ROLE_CHANGE_REFUSED, AuditEventType::STAFF_PERMISSION_DENIED, AuditEventType::STAFF_ROLE_APPROVED],
            $this->audit->types(),
        );
        self::assertSame(['actor' => self::ADMIN2, 'subject' => self::OTHER, 'role' => 'super_admin'], $this->audit->events[3]->details);

        // An approved grant cannot be approved again.
        $this->assertRefused(StaffRoleChangeRefused::NOT_PENDING, fn () => $this->roles->approve(self::ADMIN2, $grant, AuditFixtures::client()));
    }

    public function testAGranteeWhoIsAlsoAManagerCannotApproveTheirOwnSuperAdminGrant(): void
    {
        $this->directory->add(self::ADMIN2, 'active', StaffRole::SecurityAdmin);
        $this->roles->grant(self::ADMIN, self::ADMIN2, StaffRole::SuperAdmin, AuditFixtures::client());
        $grant = $this->directory->openGrantId(self::ADMIN2, StaffRole::SuperAdmin);
        self::assertIsString($grant);

        $this->assertRefused(StaffRoleChangeRefused::SAME_PERSON, fn () => $this->roles->approve(self::ADMIN2, $grant, AuditFixtures::client()));
        self::assertNotContains(StaffRole::SuperAdmin, $this->directory->activeRoles(self::ADMIN2));
    }

    public function testApprovalOfAnUnknownOrMalformedGrantIsRefused(): void
    {
        $this->assertRefused(StaffRoleChangeRefused::NOT_PENDING, fn () => $this->roles->approve(self::ADMIN, 'f0000000000000000000000000000000', AuditFixtures::client()));
        $this->assertRefused(StaffRoleChangeRefused::NOT_PENDING, fn () => $this->roles->approve(self::ADMIN, '../etc', AuditFixtures::client()));
    }

    public function testApprovalIsRefusedWhenTheGranteeWasDisabledMeanwhile(): void
    {
        $this->directory->add(self::ADMIN2, 'active', StaffRole::SecurityAdmin);
        $this->roles->grant(self::ADMIN, self::OTHER, StaffRole::SuperAdmin, AuditFixtures::client());
        $grant = $this->directory->openGrantId(self::OTHER, StaffRole::SuperAdmin);
        self::assertIsString($grant);
        $this->directory->members[self::OTHER] = 'disabled';

        $this->assertRefused(StaffRoleChangeRefused::NOT_PENDING, fn () => $this->roles->approve(self::ADMIN2, $grant, AuditFixtures::client()));
        self::assertSame('pending', $this->directory->grants[$grant]['status']);
    }

    public function testRevocationRemovesThePermissionAtTheNextRequest(): void
    {
        $this->roles->revoke(self::ADMIN, self::AGENT, StaffRole::Support, AuditFixtures::client());

        $this->expectDenied(fn () => $this->authorizer->authorize(self::AGENT, Permission::TicketReply, AuditFixtures::client()));
        self::assertSame(AuditEventType::STAFF_ROLE_REVOKED, $this->audit->events[0]->type);
        $this->assertRefused(StaffRoleChangeRefused::NOT_GRANTED, fn () => $this->roles->revoke(self::ADMIN, self::AGENT, StaffRole::Support, AuditFixtures::client()));
    }

    public function testTheLastStaffManagerCannotBeRemoved(): void
    {
        $this->assertRefused(StaffRoleChangeRefused::LAST_ADMIN, fn () => $this->roles->revoke(self::ADMIN, self::ADMIN, StaffRole::SecurityAdmin, AuditFixtures::client()));
        self::assertSame([StaffRole::SecurityAdmin], $this->directory->activeRoles(self::ADMIN), 'the refused revocation was rolled back');

        // With a second manager, the first can step down.
        $this->directory->add(self::ADMIN2, 'active', StaffRole::SecurityAdmin);
        $this->roles->revoke(self::ADMIN, self::ADMIN, StaffRole::SecurityAdmin, AuditFixtures::client());
        self::assertSame([], $this->directory->activeRoles(self::ADMIN));
    }

    public function testADisabledManagerDoesNotCountTowardsTheLastAdminRule(): void
    {
        $this->directory->add(self::ADMIN2, 'disabled', StaffRole::SecurityAdmin);

        $this->assertRefused(StaffRoleChangeRefused::LAST_ADMIN, fn () => $this->roles->revoke(self::ADMIN, self::ADMIN, StaffRole::SecurityAdmin, AuditFixtures::client()));
    }

    /** @param callable(): mixed $action */
    private function assertRefused(string $reason, callable $action): void
    {
        $before = count($this->audit->events);
        try {
            $action();
            self::fail('Expected refusal: ' . $reason);
        } catch (StaffRoleChangeRefused $e) {
            self::assertSame($reason, $e->reason);
        }
        $last = $this->audit->events[count($this->audit->events) - 1];
        self::assertCount($before + 1, $this->audit->events);
        self::assertSame(AuditEventType::STAFF_ROLE_CHANGE_REFUSED, $last->type);
        self::assertSame($reason, $last->details['reason'] ?? null);
    }

    /** @param callable(): mixed $action */
    private function expectDenied(callable $action): void
    {
        try {
            $action();
            self::fail('Expected a permission denial.');
        } catch (StaffPermissionDenied) {
        }
    }
}
