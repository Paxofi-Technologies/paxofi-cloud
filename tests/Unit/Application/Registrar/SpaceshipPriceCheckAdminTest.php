<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Application\Registrar;

use PaxofiCloud\Application\Audit\AuditEventType;
use PaxofiCloud\Application\Contracts\ProviderCredentialStatus;
use PaxofiCloud\Application\Registrar\PriceCheckItem;
use PaxofiCloud\Application\Registrar\PriceCheckRun;
use PaxofiCloud\Application\Registrar\SpaceshipPriceCheckAdmin;
use PaxofiCloud\Application\Registrar\SyncRequestRefused;
use PaxofiCloud\Application\Staff\StaffAuthorizer;
use PaxofiCloud\Application\Staff\StaffPermissionDenied;
use PaxofiCloud\Domain\Pricing\CostCurrency;
use PaxofiCloud\Domain\Registrar\CostAmount;
use PaxofiCloud\Domain\Registrar\TldName;
use PaxofiCloud\Domain\Staff\StaffRole;
use PaxofiCloud\Tests\Support\AuditFixtures;
use PaxofiCloud\Tests\Support\InMemoryAuditLog;
use PaxofiCloud\Tests\Support\InMemoryPriceCheckStore;
use PaxofiCloud\Tests\Support\InMemoryProviderSyncRequests;
use PaxofiCloud\Tests\Support\InMemoryStaffDirectory;
use PaxofiCloud\Tests\Support\MutableClock;
use PHPUnit\Framework\TestCase;

/** The Spaceship tab of Domain costs (THREAT-MODEL-DOMAIN-PRICING.md SP-3, SP-7). */
final class SpaceshipPriceCheckAdminTest extends TestCase
{
    private const string CATALOGUE = 'c0000000000000000000000000000000';
    private const string FINANCE = 'f0000000000000000000000000000000';
    private const string SUPPORT = 'e0000000000000000000000000000000';

    private InMemoryPriceCheckStore $store;
    private InMemoryProviderSyncRequests $requests;
    private InMemoryAuditLog $audit;
    private MutableClock $clock;
    /** @var array<string, array{updated_at: \DateTimeImmutable, review_due_on: \DateTimeImmutable}> */
    private array $configured = [];
    private SpaceshipPriceCheckAdmin $admin;

    protected function setUp(): void
    {
        $directory = new InMemoryStaffDirectory();
        $directory->add(self::CATALOGUE, 'active', StaffRole::Catalogue);
        $directory->add(self::FINANCE, 'active', StaffRole::Finance);
        $directory->add(self::SUPPORT, 'active', StaffRole::Support);
        $this->store = new InMemoryPriceCheckStore();
        $this->requests = new InMemoryProviderSyncRequests();
        $this->audit = new InMemoryAuditLog();
        $this->clock = new MutableClock(new \DateTimeImmutable('2026-10-25T09:00:00Z'));
        $status = new class ($this) implements ProviderCredentialStatus {
            public function __construct(private SpaceshipPriceCheckAdminTest $test)
            {
            }

            public function configured(): array
            {
                return $this->test->configured();
            }
        };
        $trail = AuditFixtures::trail($this->audit);
        $this->admin = new SpaceshipPriceCheckAdmin('spaceship-staging', new StaffAuthorizer($directory, $trail), $this->store, $this->requests, $status, $this->clock, $trail);
    }

    /** @return array<string, array{updated_at: \DateTimeImmutable, review_due_on: \DateTimeImmutable}> */
    public function configured(): array
    {
        return $this->configured;
    }

    private function connect(): void
    {
        $this->configured['spaceship-staging'] = ['updated_at' => new \DateTimeImmutable('2026-10-25T08:00:00Z'), 'review_due_on' => new \DateTimeImmutable('2027-10-25')];
    }

    public function testCatalogueSeesTheNewestCheck(): void
    {
        $this->connect();
        $this->store->save(new PriceCheckRun('a0000000000000000000000000000001', 'manual', new \DateTimeImmutable('2026-10-25T08:30:00Z'), null, 20, [
            new PriceCheckItem(new TldName('com'), 'available', CostAmount::fromDecimalText(CostCurrency::USD, '9.98'), ['renew']),
            new PriceCheckItem(new TldName('ng'), 'missing', null, []),
        ]));

        $view = $this->admin->overview(self::CATALOGUE, AuditFixtures::client());

        self::assertTrue($view['connected']);
        self::assertTrue($view['can_run']);
        self::assertNull($view['pending_since']);
        self::assertCount(20, $view['probe_tlds']);
        self::assertSame([
            'checked_at' => '2026-10-25T08:30:00+00:00',
            'trigger' => 'manual',
            'outcome' => 'done',
            'reason' => null,
            'asked' => 20,
            'answered' => 1,
            'priced' => 1,
            'items' => [
                ['tld' => 'com', 'result' => 'available', 'register' => 998, 'currency' => 'USD', 'other_priced' => ['renew']],
                ['tld' => 'ng', 'result' => 'missing', 'register' => null, 'currency' => null, 'other_priced' => []],
            ],
        ], $view['latest']);
        self::assertSame([], $this->audit->types(), 'reading is not audited');
    }

    public function testFinanceMayReadButNotRunAndSupportMayDoNeither(): void
    {
        $this->connect();
        self::assertFalse($this->admin->overview(self::FINANCE, AuditFixtures::client())['can_run']);
        foreach ([fn () => $this->admin->requestCheck(self::FINANCE, AuditFixtures::client()), fn () => $this->admin->overview(self::SUPPORT, AuditFixtures::client())] as $call) {
            try {
                $call();
                self::fail('expected StaffPermissionDenied');
            } catch (StaffPermissionDenied) {
            }
        }
        self::assertSame([], $this->requests->requests);
    }

    public function testARunIsQueuedAuditedAndLimitedToOnceInTenMinutes(): void
    {
        $this->connect();
        $this->admin->requestCheck(self::CATALOGUE, AuditFixtures::client());

        self::assertSame('spaceship', $this->requests->requests[0]['provider']);
        $view = $this->admin->overview(self::CATALOGUE, AuditFixtures::client());
        self::assertSame('2026-10-25T09:00:00+00:00', $view['pending_since']);
        self::assertSame('2026-10-25T09:10:00+00:00', $view['next_request_at']);

        $this->clock->advance('+4 minutes');
        try {
            $this->admin->requestCheck(self::CATALOGUE, AuditFixtures::client());
            self::fail('expected SyncRequestRefused');
        } catch (SyncRequestRefused $e) {
            self::assertSame(SyncRequestRefused::TOO_SOON, $e->reason);
            self::assertSame(360, $e->retryAfter);
        }
        self::assertCount(1, $this->requests->requests);
        self::assertSame([AuditEventType::PROVIDER_PRICES_SYNC_REQUESTED, AuditEventType::PROVIDER_PRICES_SYNC_REQUEST_REFUSED], $this->audit->types());
    }

    public function testNoRunUntilSpaceshipIsConnected(): void
    {
        self::assertFalse($this->admin->overview(self::CATALOGUE, AuditFixtures::client())['connected']);
        try {
            $this->admin->requestCheck(self::CATALOGUE, AuditFixtures::client());
            self::fail('expected SyncRequestRefused');
        } catch (SyncRequestRefused $e) {
            self::assertSame(SyncRequestRefused::NOT_CONNECTED, $e->reason);
        }
        self::assertSame([], $this->requests->requests);
        self::assertSame([AuditEventType::PROVIDER_PRICES_SYNC_REQUEST_REFUSED], $this->audit->types());
    }
}
