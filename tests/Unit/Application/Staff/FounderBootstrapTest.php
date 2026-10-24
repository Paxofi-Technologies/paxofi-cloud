<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Application\Staff;

use PaxofiCloud\Application\Audit\AuditEventType;
use PaxofiCloud\Application\Email\EmailTemplates;
use PaxofiCloud\Application\Staff\FounderBootstrap;
use PaxofiCloud\Application\Staff\StaffRoleChangeRefused;
use PaxofiCloud\Domain\Staff\StaffRole;
use PaxofiCloud\Tests\Support\AuditFixtures;
use PaxofiCloud\Tests\Support\FixedClock;
use PaxofiCloud\Tests\Support\InMemoryAuditLog;
use PaxofiCloud\Tests\Support\InMemoryStaffDirectory;
use PaxofiCloud\Tests\Support\RecordingOutbox;
use PHPUnit\Framework\TestCase;

/** Founder bootstrap of the Catalogue role (threat model §6.23; CEO decision 24 Oct 2026). */
final class FounderBootstrapTest extends TestCase
{
    private const string FOUNDER = 'a0000000000000000000000000000000';
    private const string OTHER = 'b0000000000000000000000000000000';

    private InMemoryStaffDirectory $directory;
    private InMemoryAuditLog $audit;
    private RecordingOutbox $outbox;
    private FounderBootstrap $founder;

    protected function setUp(): void
    {
        $this->directory = new InMemoryStaffDirectory();
        $this->directory->add(self::FOUNDER, 'active', StaffRole::SecurityAdmin);
        $this->directory->profiles[self::FOUNDER] = ['email' => 'ada@example.ng', 'full_name' => 'Ada Lovelace'];
        $this->audit = new InMemoryAuditLog();
        $this->outbox = new RecordingOutbox();
        $this->founder = new FounderBootstrap(
            $this->directory,
            $this->directory,
            new FixedClock(new \DateTimeImmutable('2026-10-24 09:00:00 UTC')),
            AuditFixtures::trail($this->audit),
            $this->outbox,
        );
    }

    public function testSoleSecurityAdminGetsCatalogueOnceAuditedAndEmailed(): void
    {
        self::assertSame([StaffRole::Catalogue], $this->founder->grantFounderRoles('ADA@example.ng', AuditFixtures::client()));

        self::assertSame([StaffRole::SecurityAdmin, StaffRole::Catalogue], $this->directory->activeRoles(self::FOUNDER));
        self::assertSame([AuditEventType::STAFF_ROLE_GRANTED], $this->audit->types());
        self::assertSame(['subject' => self::FOUNDER, 'role' => 'catalogue', 'via' => 'founder_bootstrap'], $this->audit->events[0]->details);
        self::assertSame([['to' => 'ada@example.ng', 'template' => EmailTemplates::STAFF_ROLES_ADDED, 'parameters' => ['name' => 'Ada Lovelace', 'roles' => 'catalogue']]], $this->outbox->queued);

        // Idempotent: a second run grants, audits and emails nothing.
        self::assertSame([], $this->founder->grantFounderRoles('ada@example.ng', AuditFixtures::client()));
        self::assertCount(1, $this->audit->events);
        self::assertCount(1, $this->outbox->queued);
    }

    public function testRefusedOnceASecondStaffMemberIsActive(): void
    {
        $this->directory->add(self::OTHER, 'active', StaffRole::Support);
        $this->assertRefused(StaffRoleChangeRefused::NOT_SOLE_FOUNDER, 'ada@example.ng');
    }

    public function testInvitedOrDisabledStaffDoNotCloseIt(): void
    {
        $this->directory->add(self::OTHER, 'invited');
        $this->directory->add('c0000000000000000000000000000000', 'disabled', StaffRole::SecurityAdmin);
        self::assertSame([StaffRole::Catalogue], $this->founder->grantFounderRoles('ada@example.ng', AuditFixtures::client()));
    }

    public function testRefusedForAnotherEmailOrAMalformedOne(): void
    {
        $this->assertRefused(StaffRoleChangeRefused::FOUNDER_MISMATCH, 'mallory@example.ng');
        $this->assertRefused(StaffRoleChangeRefused::FOUNDER_MISMATCH, 'not an email');
    }

    public function testRefusedWhenTheSoleMemberIsNotSecurityAdmin(): void
    {
        $directory = new InMemoryStaffDirectory();
        $directory->add(self::OTHER, 'active', StaffRole::Support);
        $directory->profiles[self::OTHER] = ['email' => 'bob@example.ng', 'full_name' => 'Bob'];
        $this->founder = new FounderBootstrap($directory, $directory, new FixedClock(new \DateTimeImmutable()), AuditFixtures::trail($this->audit), $this->outbox);
        $this->directory = $directory;

        $this->assertRefused(StaffRoleChangeRefused::FOUNDER_MISMATCH, 'bob@example.ng', self::OTHER, [StaffRole::Support]);
    }

    public function testNoActiveStaffAtAllIsRefused(): void
    {
        $directory = new InMemoryStaffDirectory();
        $this->founder = new FounderBootstrap($directory, $directory, new FixedClock(new \DateTimeImmutable()), AuditFixtures::trail($this->audit), $this->outbox);
        $this->expectException(StaffRoleChangeRefused::class);
        $this->founder->grantFounderRoles('ada@example.ng', AuditFixtures::client());
    }

    public function testNeverSuperAdmin(): void
    {
        self::assertNotContains(StaffRole::SuperAdmin, FounderBootstrap::FOUNDER_ROLES);
    }

    /** @param list<StaffRole> $roles */
    private function assertRefused(string $reason, string $email, string $staffId = self::FOUNDER, array $roles = [StaffRole::SecurityAdmin]): void
    {
        $before = count($this->audit->events);
        try {
            $this->founder->grantFounderRoles($email, AuditFixtures::client());
            self::fail('expected a refusal');
        } catch (StaffRoleChangeRefused $e) {
            self::assertSame($reason, $e->reason);
        }
        self::assertSame($roles, $this->directory->activeRoles($staffId));
        self::assertSame([], $this->outbox->queued);
        $event = $this->audit->events[$before];
        self::assertSame(AuditEventType::STAFF_ROLE_CHANGE_REFUSED, $event->type);
        self::assertSame(['via' => 'founder_bootstrap', 'reason' => $reason], $event->details);
    }
}
