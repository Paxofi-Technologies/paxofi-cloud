<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Application\Staff;

use PaxofiCloud\Application\Audit\AuditEvent;
use PaxofiCloud\Application\Audit\AuditEventType;
use PaxofiCloud\Application\Staff\StaffAuthorizer;
use PaxofiCloud\Application\Staff\StaffPermissionDenied;
use PaxofiCloud\Domain\Staff\Permission;
use PaxofiCloud\Domain\Staff\StaffRole;
use PaxofiCloud\Tests\Support\AuditFixtures;
use PaxofiCloud\Tests\Support\InMemoryAuditLog;
use PaxofiCloud\Tests\Support\InMemoryStaffDirectory;
use PHPUnit\Framework\TestCase;

/** Deny-by-default staff authorisation (SRS ACC-005; threat model §6.16, T-32). */
final class StaffAuthorizerTest extends TestCase
{
    private const string AGENT = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private InMemoryStaffDirectory $directory;
    private InMemoryAuditLog $audit;
    private StaffAuthorizer $authorizer;

    protected function setUp(): void
    {
        $this->directory = new InMemoryStaffDirectory();
        $this->audit = new InMemoryAuditLog();
        $this->authorizer = new StaffAuthorizer($this->directory, AuditFixtures::trail($this->audit));
    }

    /** ACC-005 acceptance: a Support Agent who tries to issue a refund is refused and the attempt is recorded. */
    public function testSupportAgentCannotIssueARefundAndTheAttemptIsAudited(): void
    {
        $this->directory->add(self::AGENT, 'active', StaffRole::Support);

        try {
            $this->authorizer->authorize(self::AGENT, Permission::RefundIssue, AuditFixtures::client());
            self::fail('Support must not issue refunds.');
        } catch (StaffPermissionDenied $e) {
            self::assertSame(Permission::RefundIssue, $e->permission);
        }

        self::assertCount(1, $this->audit->events);
        $event = $this->audit->events[0];
        self::assertSame(AuditEventType::STAFF_PERMISSION_DENIED, $event->type);
        self::assertSame(AuditEvent::FAILURE, $event->outcome);
        self::assertSame(['permission' => 'billing.refund.issue', 'actor' => self::AGENT], $event->details);
        self::assertNull($event->identityId, 'staff events never land in a customer activity list');
    }

    public function testGrantedPermissionReturnsThePrincipalWithoutAuditNoise(): void
    {
        $this->directory->add(self::AGENT, 'active', StaffRole::Support, StaffRole::Finance);

        $principal = $this->authorizer->authorize(self::AGENT, Permission::ReportsView, AuditFixtures::client());

        self::assertSame(self::AGENT, $principal->staffId);
        self::assertTrue($principal->can(Permission::TicketReply));
        self::assertFalse($principal->can(Permission::StaffManage));
        self::assertSame([], $this->audit->events);
    }

    public function testInvitedDisabledAndUnknownStaffHoldNothing(): void
    {
        $this->directory->add('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 'invited', StaffRole::SuperAdmin);
        $this->directory->add('cccccccccccccccccccccccccccccccc', 'disabled', StaffRole::SuperAdmin);

        foreach (['bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 'cccccccccccccccccccccccccccccccc', 'dddddddddddddddddddddddddddddddd', 'not an id'] as $staffId) {
            try {
                $this->authorizer->authorize($staffId, Permission::AuditView, AuditFixtures::client());
                self::fail('Only active staff hold permissions: ' . $staffId);
            } catch (StaffPermissionDenied) {
            }
        }

        self::assertCount(4, $this->audit->events);
        self::assertArrayNotHasKey('actor', $this->audit->events[3]->details, 'a malformed ID is never written to the audit log');
    }

    public function testStaffWithNoRolesHoldNothing(): void
    {
        $this->directory->add(self::AGENT);

        foreach (Permission::cases() as $permission) {
            try {
                $this->authorizer->authorize(self::AGENT, $permission, AuditFixtures::client());
                self::fail('Deny by default: ' . $permission->value);
            } catch (StaffPermissionDenied) {
            }
        }
        self::assertCount(count(Permission::cases()), $this->audit->events);
    }

    public function testRevocationAppliesAtTheNextCheck(): void
    {
        $this->directory->add(self::AGENT, 'active', StaffRole::Billing);
        $this->authorizer->authorize(self::AGENT, Permission::RefundIssue, AuditFixtures::client());

        $grant = $this->directory->openGrantId(self::AGENT, StaffRole::Billing);
        self::assertIsString($grant);
        $this->directory->grants[$grant]['status'] = 'revoked';

        $this->expectException(StaffPermissionDenied::class);
        $this->authorizer->authorize(self::AGENT, Permission::RefundIssue, AuditFixtures::client());
    }

    public function testPendingGrantsGiveNothing(): void
    {
        $this->directory->add(self::AGENT);
        $this->directory->addGrant('eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee', self::AGENT, StaffRole::SuperAdmin, 'pending', 'ffffffffffffffffffffffffffffffff', new \DateTimeImmutable());

        $this->expectException(StaffPermissionDenied::class);
        $this->authorizer->authorize(self::AGENT, Permission::SettingsManage, AuditFixtures::client());
    }
}
