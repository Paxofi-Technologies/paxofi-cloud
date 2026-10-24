<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Application\Audit;

use PaxofiCloud\Application\Audit\AuditEvent;
use PaxofiCloud\Application\Audit\AuditEventType;
use PaxofiCloud\Application\Audit\AuditLogQuery;
use PaxofiCloud\Application\Audit\AuditLogViewer;
use PaxofiCloud\Application\Staff\StaffAuthorizer;
use PaxofiCloud\Application\Staff\StaffPermissionDenied;
use PaxofiCloud\Domain\Staff\StaffRole;
use PaxofiCloud\Tests\Support\AuditFixtures;
use PaxofiCloud\Tests\Support\InMemoryAuditLog;
use PaxofiCloud\Tests\Support\InMemoryStaffDirectory;
use PaxofiCloud\Tests\Support\ScriptedRateLimiter;
use PHPUnit\Framework\TestCase;

/** The staff audit log page (identity threat model §6.24, T-56, T-57). */
final class AuditLogViewerTest extends TestCase
{
    private const string SECURITY = 'a0000000000000000000000000000000';
    private const string FINANCE = 'f0000000000000000000000000000000';
    private const string SUPPORT = 'e0000000000000000000000000000000';
    private const string CUSTOMER = 'c0000000000000000000000000000001';

    private InMemoryAuditLog $log;
    private ScriptedRateLimiter $limiter;
    private AuditLogViewer $viewer;

    protected function setUp(): void
    {
        $directory = new InMemoryStaffDirectory();
        $directory->add(self::SECURITY, 'active', StaffRole::SecurityAdmin);
        $directory->add(self::FINANCE, 'active', StaffRole::Finance);
        $directory->add(self::SUPPORT, 'active', StaffRole::Support);
        $this->log = new InMemoryAuditLog();
        $this->limiter = new ScriptedRateLimiter();
        $trail = AuditFixtures::trail($this->log);
        $this->viewer = new AuditLogViewer(new StaffAuthorizer($directory, $trail), $this->log, $this->limiter, $trail);

        $client = AuditFixtures::client('203.0.113.45');
        $trail->record(AuditEventType::SIGN_IN_SUCCEEDED, AuditEvent::SUCCESS, $client, self::CUSTOMER);
        $trail->record(AuditEventType::SIGN_IN_FAILED, AuditEvent::FAILURE, $client, self::CUSTOMER, details: ['reason' => 'wrong_password']);
        $trail->record(AuditEventType::STAFF_SIGNED_IN, AuditEvent::SUCCESS, $client, details: ['subject' => self::FINANCE, 'method' => 'password_codes']);
    }

    public function testSecurityAdminSeesEverythingNewestFirstWithFullContext(): void
    {
        $page = $this->viewer->page(self::SECURITY, [], AuditFixtures::client());

        self::assertTrue($page['full_context']);
        self::assertSame([3, 2, 1], array_column($page['events'], 'seq'));
        self::assertSame(['staff.signed_in', 'auth.sign_in.failed', 'auth.sign_in.succeeded'], array_column($page['events'], 'type'));
        self::assertSame('203.0.113.45', $page['events'][0]['ip']);
        self::assertSame('203.0.113.0/24', $page['events'][0]['ip_network']);
        self::assertSame(AuditFixtures::USER_AGENT, $page['events'][0]['user_agent']);
        self::assertNull($page['next_before']);
        self::assertSame(4, $page['head']['seq'], 'the head includes the audit.viewed event for this look');
    }

    public function testOtherAuditViewersSeeTheNetworkNotTheFullAddress(): void
    {
        $page = $this->viewer->page(self::FINANCE, [], AuditFixtures::client());

        self::assertFalse($page['full_context']);
        $event = $page['events'][0];
        self::assertNull($event['ip']);
        self::assertNull($event['user_agent']);
        self::assertSame('203.0.113.0/24', $event['ip_network']);
        self::assertIsArray($event['device']);
        self::assertNotSame('', $event['device']['browser']);
    }

    public function testSupportIsRefusedAndTheRefusalIsAudited(): void
    {
        try {
            $this->viewer->page(self::SUPPORT, [], AuditFixtures::client());
            self::fail('Support holds only audit.view_own');
        } catch (StaffPermissionDenied) {
        }

        self::assertSame(AuditEventType::STAFF_PERMISSION_DENIED, $this->log->types()[3]);
        self::assertCount(4, $this->log->events, 'no audit.viewed for a refused look');
    }

    public function testFiltersNarrowTheList(): void
    {
        $failures = $this->viewer->page(self::SECURITY, ['outcome' => 'failure'], AuditFixtures::client());
        self::assertSame(['auth.sign_in.failed'], array_column($failures['events'], 'type'));

        $staff = $this->viewer->page(self::SECURITY, ['group' => 'staff'], AuditFixtures::client());
        self::assertSame(['staff.signed_in'], array_column($staff['events'], 'type'));

        $member = $this->viewer->page(self::SECURITY, ['subject' => self::FINANCE], AuditFixtures::client());
        self::assertSame(['staff.signed_in'], array_column($member['events'], 'type'), 'staff events match on details.subject');

        $customer = $this->viewer->page(self::SECURITY, ['subject' => self::CUSTOMER, 'group' => 'auth'], AuditFixtures::client());
        self::assertSame([2, 1], array_column($customer['events'], 'seq'));
    }

    public function testEveryLookIsAuditedOncePerSearchPerMinute(): void
    {
        $this->viewer->page(self::SECURITY, ['group' => 'auth'], AuditFixtures::client());
        $this->viewer->page(self::SECURITY, ['group' => 'auth'], AuditFixtures::client());
        $this->viewer->page(self::SECURITY, ['group' => 'auth', 'before' => '2'], AuditFixtures::client());
        $this->viewer->page(self::SECURITY, ['group' => 'staff'], AuditFixtures::client());

        $viewed = array_values(array_filter($this->log->events, static fn (AuditEvent $e): bool => $e->type === AuditEventType::AUDIT_VIEWED));
        self::assertCount(2, $viewed, 'refreshing or paging one search is one look; a new search is another');
        self::assertSame(['subject' => self::SECURITY, 'rows' => 2, 'filter_group' => 'auth'], $viewed[0]->details);
        self::assertSame(['subject' => self::SECURITY, 'rows' => 1, 'filter_group' => 'staff'], $viewed[1]->details);
        self::assertNull($viewed[0]->identityId, 'staff are named in details, like other staff events');
    }

    public function testPagesWithACursor(): void
    {
        $client = AuditFixtures::client();
        $trail = AuditFixtures::trail($this->log);
        for ($i = 0; $i < AuditLogQuery::PAGE_SIZE; $i++) {
            $trail->record(AuditEventType::SIGNED_OUT, AuditEvent::SUCCESS, $client, self::CUSTOMER);
        }

        $first = $this->viewer->page(self::SECURITY, ['subject' => self::CUSTOMER], $client);
        self::assertCount(AuditLogQuery::PAGE_SIZE, $first['events']);
        self::assertSame(53, $first['events'][0]['seq']);
        self::assertSame(4, $first['next_before']);

        $second = $this->viewer->page(self::SECURITY, ['subject' => self::CUSTOMER, 'before' => (string) $first['next_before']], $client);
        self::assertSame([2, 1], array_column($second['events'], 'seq'));
        self::assertNull($second['next_before']);
    }

    public function testAnUnacceptedFilterIsRefusedBeforeAnySearch(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        try {
            $this->viewer->page(self::SECURITY, ['group' => "auth' OR '1'='1"], AuditFixtures::client());
        } finally {
            self::assertCount(3, $this->log->events, 'nothing recorded for a refused filter');
        }
    }
}
