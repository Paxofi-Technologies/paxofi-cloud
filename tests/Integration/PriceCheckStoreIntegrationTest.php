<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Integration;

use Paxofi\Core\Contracts\Connection;
use Paxofi\Core\Contracts\Repository;
use Paxofi\Core\Contracts\TransactionManager;
use PaxofiCloud\Application\Registrar\PriceCheckItem;
use PaxofiCloud\Application\Registrar\PriceCheckRun;
use PaxofiCloud\Bootstrap\AppFactory;
use PaxofiCloud\Domain\Pricing\CostCurrency;
use PaxofiCloud\Domain\Registrar\CostAmount;
use PaxofiCloud\Domain\Registrar\TldName;
use PaxofiCloud\Infrastructure\Container\Resolve;
use PaxofiCloud\Infrastructure\Migrations\MigrationLoader;
use PaxofiCloud\Infrastructure\Migrations\Migrator;
use PaxofiCloud\Infrastructure\Migrations\MysqlAdvisoryLock;
use PaxofiCloud\Infrastructure\Registrar\MysqlPriceCheckStore;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;

/** Migration 0019 and MysqlPriceCheckStore on MySQL 8.4 (THREAT-MODEL-DOMAIN-PRICING.md SP-2, SP-7). */
final class PriceCheckStoreIntegrationTest extends IntegrationTestCase
{
    private MysqlPriceCheckStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        self::resetDatabase();
        $container = AppFactory::create(self::basePath())->container();
        $repository = Resolve::get($container, Repository::class);
        $connection = Resolve::get($container, Connection::class);
        (new Migrator($connection, $repository, new MysqlAdvisoryLock($repository)))
            ->migrate((new MigrationLoader())->load(self::basePath() . '/migrations'));
        $this->store = new MysqlPriceCheckStore($connection, $repository, Resolve::get($container, TransactionManager::class));
    }

    public function testStoresAndReadsBackTheNewestCheck(): void
    {
        self::assertNull($this->store->latest());
        $this->store->save(new PriceCheckRun('a0000000000000000000000000000001', 'cli', new \DateTimeImmutable('2026-10-25T01:00:00Z'), 'unauthorised', 20, []));
        $newest = new PriceCheckRun('a0000000000000000000000000000002', 'manual', new \DateTimeImmutable('2026-10-25T02:00:00.123456Z'), null, 20, [
            new PriceCheckItem(new TldName('com'), 'available', CostAmount::fromDecimalText(CostCurrency::USD, '9.98'), ['renew', 'restore']),
            new PriceCheckItem(new TldName('com.ng'), 'missing', null, []),
        ]);
        $this->store->save($newest);

        $read = $this->store->latest();

        self::assertNotNull($read);
        self::assertEquals($newest, $read);
        self::assertSame(1, $read->priced());
        self::assertSame(1, $read->answered());
    }

    public function testAFailedCheckKeepsItsReason(): void
    {
        $this->store->save(new PriceCheckRun('a0000000000000000000000000000003', 'manual', new \DateTimeImmutable('2026-10-25T01:00:00Z'), 'rate_limited', 20, []));

        self::assertSame('rate_limited', $this->store->latest()?->failure);
    }

    /** @return iterable<string, array{string}> */
    public static function refusedRows(): iterable
    {
        yield 'done with a reason' => ["INSERT INTO spaceship_price_checks VALUES (REPEAT('b', 32), 'cli', NOW(6), 'done', 'transport', 20, 0, 0)"];
        yield 'failed without a reason' => ["INSERT INTO spaceship_price_checks VALUES (REPEAT('b', 32), 'cli', NOW(6), 'failed', NULL, 20, 0, 0)"];
        yield 'unknown trigger' => ["INSERT INTO spaceship_price_checks VALUES (REPEAT('b', 32), 'schedule', NOW(6), 'done', NULL, 20, 0, 0)"];
        yield 'more priced than answered' => ["INSERT INTO spaceship_price_checks VALUES (REPEAT('b', 32), 'cli', NOW(6), 'done', NULL, 20, 1, 2)"];
        yield 'more than twenty names' => ["INSERT INTO spaceship_price_checks VALUES (REPEAT('b', 32), 'cli', NOW(6), 'done', NULL, 21, 0, 0)"];
        yield 'reason with text' => ["INSERT INTO spaceship_price_checks VALUES (REPEAT('b', 32), 'cli', NOW(6), 'failed', 'Bad key!', 20, 0, 0)"];
    }

    #[DataProvider('refusedRows')]
    public function testTheTableRefusesInconsistentChecks(string $sql): void
    {
        $this->expectException(PDOException::class);
        self::pdo()->exec($sql);
    }

    public function testItemsRefuseFreeTextAndBadNames(): void
    {
        self::pdo()->exec("INSERT INTO spaceship_price_checks VALUES (REPEAT('d', 32), 'cli', NOW(6), 'done', NULL, 20, 1, 1)");
        foreach ([
            "INSERT INTO spaceship_price_check_items VALUES (REPEAT('d', 32), 'COM', 'available', NULL, NULL)",
            "INSERT INTO spaceship_price_check_items VALUES (REPEAT('d', 32), 'com', 'Available!', NULL, NULL)",
            "INSERT INTO spaceship_price_check_items VALUES (REPEAT('d', 32), 'com', 'available', NULL, 'renew, transfer')",
            "INSERT INTO spaceship_price_check_items VALUES (REPEAT('d', 32), 'paxofi-price-check-abc.com', 'available', NULL, NULL)",
        ] as $sql) {
            try {
                self::pdo()->exec($sql);
                self::fail('expected the CHECK constraint to refuse: ' . $sql);
            } catch (PDOException) {
            }
        }
        self::pdo()->exec("INSERT INTO spaceship_price_check_items VALUES (REPEAT('d', 32), 'com', 'available', 998, 'renew,transfer')");
        $count = self::pdo()->query('SELECT COUNT(*) FROM spaceship_price_check_items');
        self::assertNotFalse($count);
        self::assertSame(1, (int) $count->fetchColumn());
    }
}
