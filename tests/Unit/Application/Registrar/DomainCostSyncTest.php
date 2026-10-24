<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Application\Registrar;

use PaxofiCloud\Application\Audit\AuditTrail;
use PaxofiCloud\Application\Email\EmailTemplates;
use PaxofiCloud\Application\Registrar\DomainCostSync;
use PaxofiCloud\Application\Registrar\RegistrarUnavailable;
use PaxofiCloud\Application\Staff\StaffManagerAlerts;
use PaxofiCloud\Domain\Registrar\CostQuarantinePolicy;
use PaxofiCloud\Domain\Staff\StaffRole;
use PaxofiCloud\Tests\Support\InMemoryAuditLog;
use PaxofiCloud\Tests\Support\InMemoryDomainCostStore;
use PaxofiCloud\Tests\Support\InMemoryProviderSyncRequests;
use PaxofiCloud\Tests\Support\InMemoryStaffDirectory;
use PaxofiCloud\Tests\Support\MutableClock;
use PaxofiCloud\Tests\Support\RecordingLock;
use PaxofiCloud\Tests\Support\RecordingLogger;
use PaxofiCloud\Tests\Support\RecordingOutbox;
use PaxofiCloud\Tests\Support\ScriptedPriceFeed;
use PHPUnit\Framework\TestCase;

/** THREAT-MODEL-DOMAIN-REGISTRAR.md DR-8, DR-9, DR-10 / DR-T8, DR-T9, DR-T10. */
final class DomainCostSyncTest extends TestCase
{
    private ScriptedPriceFeed $feed;
    private InMemoryDomainCostStore $store;
    private RecordingLock $lock;
    private RecordingOutbox $outbox;
    private InMemoryAuditLog $audit;
    private MutableClock $clock;
    private InMemoryProviderSyncRequests $requests;
    /** @var list<int> */
    private array $slept = [];

    protected function setUp(): void
    {
        $this->feed = new ScriptedPriceFeed();
        $this->store = new InMemoryDomainCostStore();
        $this->requests = new InMemoryProviderSyncRequests();
        $this->lock = new RecordingLock();
        $this->outbox = new RecordingOutbox();
        $this->audit = new InMemoryAuditLog();
        // 24 Oct 2026, 01:00 UTC = 02:00 WAT, half an hour before the daily run.
        $this->clock = new MutableClock(new \DateTimeImmutable('2026-10-24T01:00:00Z'));
    }

    private function sync(): DomainCostSync
    {
        $directory = new InMemoryStaffDirectory();
        $directory->add(str_repeat('m1', 16), 'active', StaffRole::SecurityAdmin);
        $directory->profiles[str_repeat('m1', 16)] = ['email' => 'manager@example.test', 'full_name' => 'Ada Manager'];
        $directory->add(str_repeat('s1', 16), 'active', StaffRole::Support);
        $directory->profiles[str_repeat('s1', 16)] = ['email' => 'support@example.test', 'full_name' => 'Sam Support'];
        $logger = new RecordingLogger();
        $slept = &$this->slept;

        return new DomainCostSync(
            $this->feed,
            $this->store,
            $this->lock,
            new CostQuarantinePolicy(),
            new StaffManagerAlerts($directory, $this->outbox, $logger),
            new AuditTrail($this->audit, $this->clock, $logger),
            $this->clock,
            $logger,
            static function (int $seconds) use (&$slept): void {
                $slept[] = $seconds;
            },
            $this->requests,
        );
    }

    /** @return list<string> problems in queued alert emails */
    private function alerts(): array
    {
        return array_map(static fn (array $q): string => $q['parameters']['problem'] ?? '', array_values(array_filter(
            $this->outbox->queued,
            static fn (array $q): bool => $q['template'] === EmailTemplates::PRICE_SYNC_PROBLEM,
        )));
    }

    public function testStoresAnAcceptedSnapshotAndRecordsTheRun(): void
    {
        $this->feed->then(ScriptedPriceFeed::list(['com' => 1081, 'com.ng' => 900]));

        $run = $this->sync()->run('manual');

        self::assertNotNull($run);
        self::assertSame(['accepted', null, 1, 'manual'], [$run->outcome, $run->reason, $run->attempts, $run->trigger]);
        self::assertCount(1, $this->store->snapshots);
        self::assertSame($run->snapshotId, $this->store->snapshots[0]['id']);
        self::assertNull($this->store->snapshots[0]['reason']);
        self::assertSame(['provider_prices.synced'], $this->audit->types());
        self::assertSame(['provider' => 'domainnameapi', 'trigger' => 'manual', 'items' => 2, 'refused' => 0], $this->audit->events[0]->details);
        self::assertSame([], $this->alerts());
        self::assertSame(['acquire paxoficloud:sync:domainnameapi', 'release paxoficloud:sync:domainnameapi'], $this->lock->log);
    }

    public function testQuarantinesAnImplausibleListAndAlertsManagersOnly(): void
    {
        $this->feed->then(ScriptedPriceFeed::list(['a1' => 1000, 'a2' => 1000, 'a3' => 1000, 'a4' => 1000]))
            ->then(ScriptedPriceFeed::list(['a1' => 1000]));
        $sync = $this->sync();
        $sync->run('schedule');

        $run = $sync->run('schedule');

        self::assertSame('quarantined', $run?->outcome);
        self::assertSame('items_disappeared', $this->store->snapshots[1]['reason']);
        self::assertSame(4, count($this->store->latestAccepted('domainnameapi', 'live') ?? []), 'the previous prices stay in use');
        self::assertSame(['quarantined'], $this->alerts());
        self::assertSame('manager@example.test', $this->outbox->queued[0]['to'], 'only staff managers are alerted');
        self::assertSame('domainnameapi', $this->outbox->queued[0]['parameters']['provider'] ?? null);
    }

    public function testRetriesTransientFailuresWithBackoffThenSucceeds(): void
    {
        $this->feed->then(new RegistrarUnavailable('rate_limited'))
            ->then(new RegistrarUnavailable('transport'))
            ->then(ScriptedPriceFeed::list(['com' => 1081]));

        $run = $this->sync()->run('schedule');

        self::assertSame(['accepted', 3], [$run?->outcome, $run?->attempts]);
        self::assertSame([2, 8], $this->slept);
    }

    public function testGivesUpAfterFourAttemptsAndStoresNothing(): void
    {
        foreach (range(1, 4) as $i) {
            $this->feed->then(new RegistrarUnavailable('provider_error'));
        }

        $run = $this->sync()->run('schedule');

        self::assertSame(['failed', 'provider_error', 4], [$run?->outcome, $run?->reason, $run?->attempts]);
        self::assertSame([2, 8, 30], $this->slept);
        self::assertSame([], $this->store->snapshots);
        self::assertSame(['provider_prices.sync_failed'], $this->audit->types());
        self::assertSame([], $this->alerts(), 'one failure does not alert');
    }

    public function testRefusedCredentialIsNotRetriedAndAlertsOncePerStreak(): void
    {
        $this->feed->then(new RegistrarUnavailable('unauthorised'))
            ->then(new RegistrarUnavailable('unauthorised'))
            ->then(ScriptedPriceFeed::list(['com' => 1081]))
            ->then(new RegistrarUnavailable('credential_invalid'));
        $sync = $this->sync();

        $first = $sync->run('schedule');
        $sync->run('schedule');
        $sync->run('manual');
        $sync->run('schedule');

        self::assertSame(1, $first?->attempts, 'never retried');
        self::assertSame([], $this->slept);
        self::assertSame(['credential_refused', 'credential_refused'], $this->alerts(), 'one alert per streak, again after a success');
    }

    public function testAlertsExactlyOnceOnTheThirdFailureInARow(): void
    {
        foreach (range(1, 5) as $run) {
            foreach (range(1, 4) as $attempt) {
                $this->feed->then(new RegistrarUnavailable('transport'));
            }
        }
        $sync = $this->sync();

        foreach (range(1, 5) as $run) {
            $sync->run('schedule');
            self::assertSame($run >= 3 ? ['repeated_failures'] : [], $this->alerts(), 'after run ' . $run);
        }
    }

    public function testSkipsWhenAnotherRunHoldsTheLock(): void
    {
        $this->lock->busy = true;

        self::assertNull($this->sync()->run('manual'));
        self::assertSame(0, $this->feed->calls);
        self::assertSame([], $this->store->runs);
    }

    public function testReleasesTheLockWhenTheStoreFails(): void
    {
        $this->feed->then(ScriptedPriceFeed::list(['com' => 1081]));
        $store = new class extends InMemoryDomainCostStore {
            public function saveSnapshot(string $provider, \PaxofiCloud\Domain\Registrar\TldCostList $list, ?string $quarantineReason, \DateTimeImmutable $fetchedAt): string
            {
                throw new \RuntimeException('database down');
            }
        };
        $this->store = $store;

        try {
            $this->sync()->run('manual');
            self::fail('expected the store failure to surface');
        } catch (\RuntimeException) {
            self::assertSame([], $this->lock->held, 'lock released');
        }
    }

    public function testRunsDailyAt0230WatOnlyOnce(): void
    {
        $this->feed->then(ScriptedPriceFeed::list(['com' => 1081]))->then(ScriptedPriceFeed::list(['com' => 1081]));
        $sync = $this->sync();
        $due = static function () use ($sync): string {
            $run = $sync->runIfDue();

            return $run === null ? 'not due' : $run->outcome;
        };

        self::assertSame('not due', $due(), '02:00 WAT: not yet');
        $this->clock->advance('+30 minutes');
        self::assertSame('accepted', $due(), '02:30 WAT: due');
        $this->clock->advance('+5 minutes');
        self::assertSame('not due', $due(), 'already ran today');
        $this->clock->advance('+20 hours');
        self::assertSame('not due', $due(), '22:35 WAT: still today');
        $this->clock->advance('+4 hours');
        self::assertSame('accepted', $due(), 'next day 02:35 WAT');
        self::assertSame(2, $this->feed->calls);
    }

    public function testAManualRunDoesNotReplaceTheDailyRun(): void
    {
        $this->feed->then(ScriptedPriceFeed::list(['com' => 1081]))->then(ScriptedPriceFeed::list(['com' => 1081]));
        $this->clock->advance('+1 hour');
        $sync = $this->sync();
        $sync->run('manual');

        self::assertSame('schedule', $sync->runIfDue()?->trigger);
    }

    /** DR-12: "Sync now" requests run once however many are waiting, as a manual run. */
    public function testRunsOnceForWaitingSyncNowRequests(): void
    {
        $this->feed->then(ScriptedPriceFeed::list(['com' => 1081]));
        $sync = $this->sync();
        $requested = static function () use ($sync): string {
            return $sync->runIfRequested()->trigger ?? 'nothing';
        };
        self::assertSame('nothing', $requested(), 'nothing requested');

        $provider = $this->feed->provider();
        $this->requests->add($provider, str_repeat('m1', 16), $this->clock->now());
        $this->requests->add($provider, str_repeat('m1', 16), $this->clock->now());
        $this->requests->add('other', str_repeat('m1', 16), $this->clock->now());

        self::assertSame('manual', $requested());
        self::assertSame('nothing', $requested(), 'both requests were used up by one run');
        self::assertSame(1, $this->feed->calls);
        self::assertNull($this->requests->pendingSince($provider));
        self::assertNotNull($this->requests->pendingSince('other'), 'another provider is untouched');
    }
}
