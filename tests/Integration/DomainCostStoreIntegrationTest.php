<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Integration;

use Paxofi\Core\Contracts\Connection;
use Paxofi\Core\Contracts\Repository;
use Paxofi\Core\Contracts\TransactionManager;
use PaxofiCloud\Application\Registrar\SyncRun;
use PaxofiCloud\Bootstrap\AppFactory;
use PaxofiCloud\Domain\Pricing\CostCurrency;
use PaxofiCloud\Domain\Registrar\CostAmount;
use PaxofiCloud\Domain\Registrar\TldCost;
use PaxofiCloud\Domain\Registrar\TldCostList;
use PaxofiCloud\Domain\Registrar\TldName;
use PaxofiCloud\Infrastructure\Container\Resolve;
use PaxofiCloud\Infrastructure\Database\MysqlNamedLock;
use PaxofiCloud\Infrastructure\Migrations\MigrationLoader;
use PaxofiCloud\Infrastructure\Migrations\Migrator;
use PaxofiCloud\Infrastructure\Migrations\MysqlAdvisoryLock;
use PaxofiCloud\Infrastructure\Persistence\Row;
use PaxofiCloud\Infrastructure\Registrar\MysqlDomainCostStore;
use PaxofiCloud\Tests\Support\ScriptedPriceFeed;
use PDOException;

/** Migration 0013, MysqlDomainCostStore and MysqlNamedLock on MySQL 8.4 (CD-13; DR-8, DR-10). */
final class DomainCostStoreIntegrationTest extends IntegrationTestCase
{
    private MysqlDomainCostStore $store;
    private Repository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        self::resetDatabase();
        $container = AppFactory::create(self::basePath())->container();
        $this->repository = Resolve::get($container, Repository::class);
        $connection = Resolve::get($container, Connection::class);
        (new Migrator($connection, $this->repository, new MysqlAdvisoryLock($this->repository)))
            ->migrate((new MigrationLoader())->load(self::basePath() . '/migrations'));
        $this->store = new MysqlDomainCostStore($connection, $this->repository, Resolve::get($container, TransactionManager::class));
    }

    public function testStoresAndReadsBackASnapshotExactly(): void
    {
        $usd = CostCurrency::USD;
        $com = new TldCost(new TldName('com'), CostAmount::ofMinor($usd, 1081), CostAmount::ofMinor($usd, 1125), CostAmount::ofMinor($usd, 1081), null, 1, 10, 'active');
        $ng = new TldCost(new TldName('com.ng'), CostAmount::ofMinor($usd, 900), CostAmount::ofMinor($usd, 900), null, CostAmount::ofMinor($usd, 4500), 1, 2, 'code_1');
        $id = $this->store->saveSnapshot('domainnameapi', new TldCostList([$com, $ng], ['bad_price' => 2, 'unsupported_currency' => 1], 'live'), null, new \DateTimeImmutable('2026-10-24T01:30:00Z'));

        $items = $this->store->latestAccepted('domainnameapi', 'live');

        self::assertNotNull($items);
        self::assertEquals(['com' => $com, 'com.ng' => $ng], $items);
        $row = $this->repository->fetchAll('SELECT status, item_count, refused_count, refused_summary FROM domain_cost_snapshots WHERE id = :id', ['id' => $id])[0] ?? [];
        self::assertSame(['accepted', 2, 3], [Row::string($row, 'status'), Row::int($row, 'item_count'), Row::int($row, 'refused_count')]);
        self::assertSame(['bad_price' => 2, 'unsupported_currency' => 1], json_decode(Row::string($row, 'refused_summary'), true));
        self::assertNull($this->store->latestAccepted('domainnameapi', 'ote'), 'environments are kept apart (DR-T7)');
    }

    public function testInsertsLargeListsInBatchesAndIgnoresQuarantinedSnapshots(): void
    {
        $prices = [];
        foreach (range(1, 250) as $i) {
            $prices['ext' . $i] = 1000 + $i;
        }
        $this->store->saveSnapshot('domainnameapi', ScriptedPriceFeed::list($prices), null, new \DateTimeImmutable('2026-10-23T01:30:00Z'));
        $this->store->saveSnapshot('domainnameapi', ScriptedPriceFeed::list(['ext1' => 1]), 'items_disappeared', new \DateTimeImmutable('2026-10-24T01:30:00Z'));

        $latest = $this->store->latestAccepted('domainnameapi', 'live');

        self::assertCount(250, $latest ?? []);
        self::assertSame(1250, ($latest ?? [])['ext250']->register->minor);
    }

    public function testRecordsRunsNewestFirst(): void
    {
        $snapshot = $this->store->saveSnapshot('domainnameapi', ScriptedPriceFeed::list(['com' => 1081]), null, new \DateTimeImmutable('2026-10-24T01:30:00Z'));
        $this->store->recordRun(new SyncRun('domainnameapi', 'schedule', 'failed', 'transport', null, 4, new \DateTimeImmutable('2026-10-23T01:30:00Z'), new \DateTimeImmutable('2026-10-23T01:31:00Z')));
        $this->store->recordRun(new SyncRun('domainnameapi', 'manual', 'accepted', null, $snapshot, 1, new \DateTimeImmutable('2026-10-24T06:00:00Z'), new \DateTimeImmutable('2026-10-24T06:00:05Z')));

        $runs = $this->store->recentRuns('domainnameapi', 10);

        self::assertSame(['manual', 'schedule'], array_map(static fn (SyncRun $r): string => $r->trigger, $runs));
        self::assertSame([$snapshot, null], array_map(static fn (SyncRun $r): ?string => $r->snapshotId, $runs));
        self::assertSame('2026-10-23T01:30:00+00:00', $this->store->lastStartedAt('domainnameapi', 'schedule')?->format(\DATE_ATOM));
        self::assertNull($this->store->lastStartedAt('domainnameapi', 'cli'));
    }

    /** @return iterable<string, array{string}> */
    public static function malformedRows(): iterable
    {
        yield 'zero cost' => ["INSERT INTO domain_cost_items VALUES (:s, 'net', 'USD', 0, 1, NULL, NULL, 1, 10, 'active')"];
        yield 'bad extension' => ["INSERT INTO domain_cost_items VALUES (:s, 'com/../x', 'USD', 1, 1, NULL, NULL, 1, 10, 'active')"];
        yield 'unknown currency' => ["INSERT INTO domain_cost_items VALUES (:s, 'net', 'TRY', 1, 1, NULL, NULL, 1, 10, 'active')"];
        yield 'years out of range' => ["INSERT INTO domain_cost_items VALUES (:s, 'net', 'USD', 1, 1, NULL, NULL, 3, 2, 'active')"];
        yield 'quarantine without reason' => ["INSERT INTO domain_cost_snapshots VALUES (REPEAT('b', 32), 'domainnameapi', 'live', 'quarantined', NULL, 0, 0, JSON_OBJECT(), NOW(6))"];
        yield 'accepted with reason' => ["INSERT INTO domain_cost_snapshots VALUES (REPEAT('b', 32), 'domainnameapi', 'live', 'accepted', 'empty', 0, 0, JSON_OBJECT(), NOW(6))"];
        yield 'unknown environment' => ["INSERT INTO domain_cost_snapshots VALUES (REPEAT('b', 32), 'domainnameapi', 'prod', 'accepted', NULL, 0, 0, JSON_OBJECT(), NOW(6))"];
        yield 'failed run with snapshot' => ["INSERT INTO provider_sync_runs VALUES (REPEAT('c', 32), 'domainnameapi', 'domain_costs', 'schedule', 'failed', 'transport', :s, 1, NOW(6), NOW(6))"];
        yield 'accepted run without snapshot' => ["INSERT INTO provider_sync_runs VALUES (REPEAT('c', 32), 'domainnameapi', 'domain_costs', 'schedule', 'accepted', NULL, NULL, 1, NOW(6), NOW(6))"];
        yield 'failed run without reason' => ["INSERT INTO provider_sync_runs VALUES (REPEAT('c', 32), 'domainnameapi', 'domain_costs', 'schedule', 'failed', NULL, NULL, 1, NOW(6), NOW(6))"];
        yield 'too many attempts' => ["INSERT INTO provider_sync_runs VALUES (REPEAT('c', 32), 'domainnameapi', 'domain_costs', 'schedule', 'failed', 'transport', NULL, 5, NOW(6), NOW(6))"];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedRows')]
    public function testTheDatabaseRefusesMalformedRows(string $sql): void
    {
        $snapshot = $this->store->saveSnapshot('domainnameapi', ScriptedPriceFeed::list(['com' => 1081]), null, new \DateTimeImmutable('2026-10-24T01:30:00Z'));
        $statement = self::pdo()->prepare($sql);

        $this->expectException(PDOException::class);
        $statement->execute(str_contains($sql, ':s') ? ['s' => $snapshot] : []);
    }

    public function testNamedLockIsExclusiveAcrossSessions(): void
    {
        $lock = new MysqlNamedLock($this->repository);
        self::assertTrue($lock->acquire('paxoficloud:sync:domainnameapi'));

        $other = self::pdo()->query("SELECT GET_LOCK('paxoficloud:sync:domainnameapi', 0)");
        self::assertNotFalse($other);
        self::assertSame(0, (int) $other->fetchColumn(), 'a second session cannot take it');

        $lock->release('paxoficloud:sync:domainnameapi');
        $again = self::pdo()->query("SELECT GET_LOCK('paxoficloud:sync:domainnameapi', 0)");
        self::assertNotFalse($again);
        self::assertSame(1, (int) $again->fetchColumn());
    }
}
