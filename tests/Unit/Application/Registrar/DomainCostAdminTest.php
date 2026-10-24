<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Application\Registrar;

use PaxofiCloud\Application\Audit\AuditEventType;
use PaxofiCloud\Application\Registrar\DomainCostAdmin;
use PaxofiCloud\Application\Registrar\SyncRequestRefused;
use PaxofiCloud\Application\Registrar\SyncRun;
use PaxofiCloud\Application\Staff\StaffAuthorizer;
use PaxofiCloud\Application\Staff\StaffPermissionDenied;
use PaxofiCloud\Domain\Staff\StaffRole;
use PaxofiCloud\Tests\Support\AuditFixtures;
use PaxofiCloud\Tests\Support\InMemoryAuditLog;
use PaxofiCloud\Tests\Support\InMemoryDomainCostStore;
use PaxofiCloud\Tests\Support\InMemoryProviderSyncRequests;
use PaxofiCloud\Tests\Support\InMemoryStaffDirectory;
use PaxofiCloud\Tests\Support\MutableClock;
use PaxofiCloud\Tests\Support\ScriptedPriceFeed;
use PHPUnit\Framework\TestCase;

/** The admin domain costs page and "Sync now" (THREAT-MODEL-DOMAIN-REGISTRAR.md DR-12, DR-13). */
final class DomainCostAdminTest extends TestCase
{
    private const string CATALOGUE = 'c0000000000000000000000000000000';
    private const string FINANCE = 'f0000000000000000000000000000000';
    private const string SUPPORT = 'e0000000000000000000000000000000';

    private InMemoryDomainCostStore $store;
    private InMemoryProviderSyncRequests $requests;
    private InMemoryAuditLog $audit;
    private MutableClock $clock;
    private DomainCostAdmin $admin;

    protected function setUp(): void
    {
        $directory = new InMemoryStaffDirectory();
        $directory->add(self::CATALOGUE, 'active', StaffRole::Catalogue);
        $directory->add(self::FINANCE, 'active', StaffRole::Finance);
        $directory->add(self::SUPPORT, 'active', StaffRole::Support);
        $this->store = new InMemoryDomainCostStore();
        $this->requests = new InMemoryProviderSyncRequests();
        $this->audit = new InMemoryAuditLog();
        $this->clock = new MutableClock(new \DateTimeImmutable('2026-10-24T09:00:00Z'));
        $trail = AuditFixtures::trail($this->audit);
        $this->admin = new DomainCostAdmin('domainnameapi', 'live', new StaffAuthorizer($directory, $trail), $this->store, $this->requests, $this->clock, $trail);
    }

    public function testCatalogueSeesTheNewestAcceptedListAndMaySync(): void
    {
        $this->store->saveSnapshot('domainnameapi', ScriptedPriceFeed::list(['com.ng' => 900, 'com' => 1081]), null, new \DateTimeImmutable('2026-10-24T01:30:00Z'));
        $this->store->recordRun(new SyncRun('domainnameapi', 'schedule', 'accepted', null, 'snap0000000000000000000000000001', 1, new \DateTimeImmutable('2026-10-24T01:30:00Z'), new \DateTimeImmutable('2026-10-24T01:30:05Z')));

        $view = $this->admin->overview(self::CATALOGUE, AuditFixtures::client());

        self::assertTrue($view['can_sync']);
        self::assertSame('live', $view['environment']);
        self::assertSame('02:30 WAT', $view['daily_at']);
        self::assertSame(['status' => 'accepted', 'reason' => null, 'items' => 2, 'refused' => 0, 'fetched_at' => '2026-10-24T01:30:00+00:00'], $view['latest']);
        self::assertNull($view['quarantined']);
        self::assertSame(['com', 'com.ng'], array_column($view['items'], 'tld'), 'sorted by extension');
        self::assertSame(1081, $view['items'][0]['register']);
        self::assertSame('USD', $view['items'][0]['currency']);
        self::assertSame([['trigger' => 'schedule', 'outcome' => 'accepted', 'reason' => null, 'attempts' => 1, 'started_at' => '2026-10-24T01:30:00+00:00', 'finished_at' => '2026-10-24T01:30:05+00:00']], $view['runs']);
        self::assertNull($view['pending_since']);
        self::assertNull($view['next_request_at']);
        self::assertSame([], $this->audit->types(), 'reading is not audited');
    }

    public function testFinanceMayReadButNotSync(): void
    {
        self::assertFalse($this->admin->overview(self::FINANCE, AuditFixtures::client())['can_sync']);

        $this->expectException(StaffPermissionDenied::class);
        try {
            $this->admin->requestSync(self::FINANCE, AuditFixtures::client());
        } finally {
            self::assertSame([], $this->requests->requests);
            self::assertSame([AuditEventType::STAFF_PERMISSION_DENIED], $this->audit->types());
        }
    }

    public function testSupportSeesNoCosts(): void
    {
        $this->expectException(StaffPermissionDenied::class);
        $this->admin->overview(self::SUPPORT, AuditFixtures::client());
    }

    public function testQuarantinedPricesAreNeverListed(): void
    {
        $this->store->saveSnapshot('domainnameapi', ScriptedPriceFeed::list(['com' => 1081]), null, new \DateTimeImmutable('2026-10-23T01:30:00Z'));
        $this->store->saveSnapshot('domainnameapi', ScriptedPriceFeed::list(['com' => 9999]), 'costs_jumped', new \DateTimeImmutable('2026-10-24T01:30:00Z'));
        $this->store->saveSnapshot('domainnameapi', ScriptedPriceFeed::list(['com' => 1], 'ote'), null, new \DateTimeImmutable('2026-10-24T02:00:00Z'));

        $view = $this->admin->overview(self::CATALOGUE, AuditFixtures::client());

        self::assertSame([1081], array_column($view['items'], 'register'), 'last accepted live prices only; never quarantined or OTE');
        self::assertSame('costs_jumped', $view['quarantined']['reason'] ?? null);
        self::assertSame('2026-10-23T01:30:00+00:00', $view['latest']['fetched_at'] ?? null);
    }

    public function testNoSnapshotYet(): void
    {
        $view = $this->admin->overview(self::CATALOGUE, AuditFixtures::client());

        self::assertNull($view['latest']);
        self::assertSame([], $view['items']);
    }

    public function testSyncNowIsQueuedAuditedAndLimitedToOncePerTenMinutes(): void
    {
        $this->admin->requestSync(self::CATALOGUE, AuditFixtures::client());

        self::assertCount(1, $this->requests->requests);
        self::assertSame(['actor' => self::CATALOGUE, 'provider' => 'domainnameapi'], $this->audit->events[0]->details);
        $view = $this->admin->overview(self::CATALOGUE, AuditFixtures::client());
        self::assertSame('2026-10-24T09:00:00+00:00', $view['pending_since']);
        self::assertSame('2026-10-24T09:10:00+00:00', $view['next_request_at']);

        $this->clock->advance('+9 minutes');
        try {
            $this->admin->requestSync(self::CATALOGUE, AuditFixtures::client());
            self::fail('expected too_soon');
        } catch (SyncRequestRefused $e) {
            self::assertSame(SyncRequestRefused::TOO_SOON, $e->reason);
            self::assertSame(60, $e->retryAfter);
        }
        self::assertCount(1, $this->requests->requests);
        self::assertSame([AuditEventType::PROVIDER_PRICES_SYNC_REQUESTED, AuditEventType::PROVIDER_PRICES_SYNC_REQUEST_REFUSED], $this->audit->types());

        // Claimed by the worker: no longer pending, but the limit still counts from the request.
        $this->requests->claimAll('domainnameapi', $this->clock->now());
        self::assertNull($this->admin->overview(self::CATALOGUE, AuditFixtures::client())['pending_since']);

        $this->clock->advance('+1 minute');
        $this->admin->requestSync(self::CATALOGUE, AuditFixtures::client());
        self::assertCount(2, $this->requests->requests);
        self::assertSame('2026-10-24T09:20:00+00:00', $this->admin->overview(self::CATALOGUE, AuditFixtures::client())['next_request_at']);
    }
}
