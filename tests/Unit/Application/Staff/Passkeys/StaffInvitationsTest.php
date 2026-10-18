<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Application\Staff\Passkeys;

use PaxofiCloud\Application\Audit\AuditEventType;
use PaxofiCloud\Application\Email\EmailTemplates;
use PaxofiCloud\Application\Staff\Passkeys\StaffInvitationRefused;
use PaxofiCloud\Application\Staff\StaffPermissionDenied;
use PaxofiCloud\Domain\Staff\Permission;
use PaxofiCloud\Domain\Staff\StaffRole;
use PaxofiCloud\Tests\Support\AuditFixtures;
use PaxofiCloud\Tests\Support\StaffPasskeyFixtures;
use PHPUnit\Framework\TestCase;

/** Staff invitations and the first-admin bootstrap (threat model D-11, §6.17). */
final class StaffInvitationsTest extends TestCase
{
    private const string ADMIN = 'a0000000000000000000000000000000';

    private StaffPasskeyFixtures $f;

    protected function setUp(): void
    {
        $this->f = new StaffPasskeyFixtures();
    }

    public function testBootstrapInvitesTheFirstSecurityAdminByEmailOnly(): void
    {
        $staffId = $this->f->invitations->bootstrap('ada@example.test', 'Ada Lovelace', AuditFixtures::client());

        self::assertSame('invited', $this->f->directory->members[$staffId]);
        self::assertSame([StaffRole::SecurityAdmin], $this->f->directory->activeRoles($staffId), 'granted, but an invited member holds nothing yet');
        $this->assertHoldsNothing($staffId);

        self::assertCount(1, $this->f->outbox->queued);
        $mail = $this->f->outbox->queued[0];
        self::assertSame('ada@example.test', $mail['to']);
        self::assertSame(EmailTemplates::STAFF_INVITATION, $mail['template']);
        $token = $this->f->lastToken();
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $token);
        self::assertArrayHasKey(hash('sha256', $token), $this->f->store->invitations, 'only the hash is stored');
        self::assertNull($this->f->store->invitations[hash('sha256', $token)]['invited_by']);

        self::assertSame([AuditEventType::STAFF_BOOTSTRAP_INVITED], $this->f->audit->types());
        self::assertStringNotContainsString($token, (string) json_encode($this->f->audit->events[0]->details));
    }

    public function testBootstrapRefusesOnceAStaffManagerIsActive(): void
    {
        $this->f->directory->add(self::ADMIN, 'active', StaffRole::SecurityAdmin);

        $this->expectRefused(StaffInvitationRefused::MANAGERS_EXIST, fn () => $this->f->invitations->bootstrap('bob@example.test', 'Bob', AuditFixtures::client()));
        self::assertSame([], $this->f->outbox->queued);
        self::assertSame([], $this->f->store->profiles);
    }

    public function testAStaffManagerInvitesWithAStartingRole(): void
    {
        $this->f->directory->add(self::ADMIN, 'active', StaffRole::SecurityAdmin);

        $staffId = $this->f->invitations->invite(self::ADMIN, 'carol@example.test', 'Carol', StaffRole::Support, AuditFixtures::client());

        self::assertSame([StaffRole::Support], $this->f->directory->activeRoles($staffId));
        self::assertSame(self::ADMIN, $this->f->store->invitations[hash('sha256', $this->f->lastToken())]['invited_by']);
        self::assertSame([AuditEventType::STAFF_INVITED], $this->f->audit->types());
        self::assertSame(['actor' => self::ADMIN, 'subject' => $staffId, 'role' => 'support'], $this->f->audit->events[0]->details);
    }

    public function testInvitingNeedsStaffManage(): void
    {
        $this->f->directory->add(self::ADMIN, 'active', StaffRole::Support);

        $this->expectException(StaffPermissionDenied::class);
        try {
            $this->f->invitations->invite(self::ADMIN, 'carol@example.test', 'Carol', StaffRole::Support, AuditFixtures::client());
        } finally {
            self::assertSame([], $this->f->outbox->queued);
        }
    }

    public function testSuperAdminCannotBeAStartingRole(): void
    {
        $this->f->directory->add(self::ADMIN, 'active', StaffRole::SecurityAdmin);

        $this->expectRefused(StaffInvitationRefused::ROLE_NEEDS_APPROVAL, fn () => $this->f->invitations->invite(self::ADMIN, 'eve@example.test', 'Eve', StaffRole::SuperAdmin, AuditFixtures::client()));
        self::assertSame([], $this->f->outbox->queued);
    }

    public function testAnAddressIsInvitedOnce(): void
    {
        $this->f->directory->add(self::ADMIN, 'active', StaffRole::SecurityAdmin);
        $this->f->invitations->invite(self::ADMIN, 'carol@example.test', 'Carol', StaffRole::Support, AuditFixtures::client());

        $this->expectRefused(StaffInvitationRefused::ALREADY_STAFF, fn () => $this->f->invitations->invite(self::ADMIN, 'CAROL@example.test', 'Carol', StaffRole::Billing, AuditFixtures::client()));
        self::assertCount(1, $this->f->outbox->queued);
    }

    public function testMalformedInputIsRefused(): void
    {
        foreach ([['not-an-email', 'Name'], ['x@example.test', ''], ['x@example.test', str_repeat('a', 101)], ['x@example.test', "Bad\u{0007}Name"], ['x@example.test', '<script>']] as [$email, $name]) {
            $this->expectRefused(StaffInvitationRefused::INVALID_INPUT, fn () => $this->f->invitations->bootstrap($email, $name, AuditFixtures::client()));
        }
        self::assertSame([], $this->f->outbox->queued);
    }

    private function assertHoldsNothing(string $staffId): void
    {
        $authorizer = new \PaxofiCloud\Application\Staff\StaffAuthorizer($this->f->directory, AuditFixtures::trail());
        $this->expectRefusedPermission(fn () => $authorizer->authorize($staffId, Permission::StaffManage, AuditFixtures::client()));
    }

    /** @param callable(): mixed $action */
    private function expectRefusedPermission(callable $action): void
    {
        try {
            $action();
            self::fail('Expected a permission denial.');
        } catch (StaffPermissionDenied) {
        }
    }

    /** @param callable(): mixed $action */
    private function expectRefused(string $reason, callable $action): void
    {
        try {
            $action();
            self::fail('Expected refusal: ' . $reason);
        } catch (StaffInvitationRefused $e) {
            self::assertSame($reason, $e->reason);
        }
        $last = $this->f->audit->events[count($this->f->audit->events) - 1];
        self::assertSame(AuditEventType::STAFF_INVITATION_REFUSED, $last->type);
        self::assertSame($reason, $last->details['reason'] ?? null);
    }
}
