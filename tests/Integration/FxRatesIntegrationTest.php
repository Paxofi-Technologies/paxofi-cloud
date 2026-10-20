<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Integration;

use Paxofi\Core\Contracts\Connection;
use Paxofi\Core\Contracts\Repository;
use Paxofi\Core\Contracts\TransactionManager;
use PaxofiCloud\Bootstrap\AppFactory;
use PaxofiCloud\Domain\Pricing\CostCurrency;
use PaxofiCloud\Domain\Pricing\FxRateVerdict;
use PaxofiCloud\Infrastructure\Container\Resolve;
use PaxofiCloud\Infrastructure\Migrations\MigrationLoader;
use PaxofiCloud\Infrastructure\Migrations\Migrator;
use PaxofiCloud\Infrastructure\Migrations\MysqlAdvisoryLock;
use PaxofiCloud\Infrastructure\Pricing\MysqlFxRateStore;
use PaxofiCloud\Tests\Support\PricingFixtures;
use PDOException;

/** Migration 0011 and MysqlFxRateStore on MySQL 8.4 (PRC-013; CD-16). */
final class FxRatesIntegrationTest extends IntegrationTestCase
{
    private MysqlFxRateStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        self::resetDatabase();
        $container = AppFactory::create(self::basePath())->container();
        $repository = Resolve::get($container, Repository::class);
        $connection = Resolve::get($container, Connection::class);
        (new Migrator($connection, $repository, new MysqlAdvisoryLock($repository)))
            ->migrate((new MigrationLoader())->load(self::basePath() . '/migrations'));
        $this->store = new MysqlFxRateStore($connection, $repository, Resolve::get($container, TransactionManager::class));
    }

    public function testRecordsAndReadsBackExactDecimals(): void
    {
        $this->store->record(PricingFixtures::rates('1332.819531', '0.891477', 'exchangerate-api-open'), FxRateVerdict::Accepted, new \DateTimeImmutable('2026-10-20T06:00:00Z'));
        $this->store->record(PricingFixtures::rates('1500.5', '0.95', 'exchangerate-api-open'), FxRateVerdict::Held, new \DateTimeImmutable('2026-10-21T06:00:00Z'));

        $accepted = $this->store->latestAccepted();
        self::assertNotNull($accepted);
        self::assertSame('1332.819531', $accepted->rates->rate(CostCurrency::NGN)->value);
        self::assertSame('0.891477', $accepted->rates->rate(CostCurrency::EUR)->value);
        self::assertSame('2026-10-20T06:00:00+00:00', $accepted->fetchedAt->format(\DATE_ATOM));

        $recent = $this->store->recent(5);
        self::assertSame([FxRateVerdict::Held, FxRateVerdict::Accepted], array_map(static fn ($s) => $s->verdict, $recent));
    }

    public function testTheDatabaseRefusesMalformedRates(): void
    {
        $this->store->record(PricingFixtures::rates(), FxRateVerdict::Accepted, new \DateTimeImmutable('2026-10-20T06:00:00Z'));
        $query = self::pdo()->query('SELECT id FROM fx_rate_sets LIMIT 1');
        self::assertNotFalse($query);
        $setId = $query->fetchColumn();
        self::assertIsString($setId);

        foreach (['0', '-1', '1e3', '1.1234567890123', 'abc'] as $bad) {
            try {
                $statement = self::pdo()->prepare('INSERT INTO fx_rate_values (set_id, currency, per_usd) VALUES (?, ?, ?)');
                $statement->execute([$setId, 'GBP', $bad]);
                self::fail('a malformed rate or currency was stored');
            } catch (PDOException) {
                $this->addToAssertionCount(1);
            }
        }
        try {
            self::pdo()->prepare("INSERT INTO fx_rate_sets (id, source, verdict, published_at, fetched_at, created_at) VALUES (REPEAT('a', 32), 'x', 'approved', NOW(), NOW(), NOW())")->execute();
            self::fail('an unknown verdict was stored');
        } catch (PDOException) {
            $this->addToAssertionCount(1);
        }
    }
}
