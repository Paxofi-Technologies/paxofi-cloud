<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Integration;

use Paxofi\Core\Contracts\Connection;
use Paxofi\Core\Contracts\Repository;
use PaxofiCloud\Bootstrap\AppFactory;
use PaxofiCloud\Infrastructure\Container\Resolve;
use PaxofiCloud\Infrastructure\Migrations\Migration;
use PaxofiCloud\Infrastructure\Migrations\MigrationError;
use PaxofiCloud\Infrastructure\Migrations\MigrationLoader;
use PaxofiCloud\Infrastructure\Migrations\Migrator;
use PaxofiCloud\Infrastructure\Migrations\MysqlAdvisoryLock;

final class MigrationsIntegrationTest extends IntegrationTestCase
{
    private Migrator $migrator;
    private Repository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        self::resetDatabase();

        $container = AppFactory::create(self::basePath())->container();
        $this->repository = Resolve::get($container, Repository::class);
        $this->migrator = new Migrator(Resolve::get($container, Connection::class), $this->repository, new MysqlAdvisoryLock($this->repository));
    }

    protected function tearDown(): void
    {
        if (getenv('PAXOFICLOUD_INTEGRATION') === '1') {
            self::resetDatabase();
        }
    }

    public function testAppliesFixturesAgainstRealMysqlExactlyOnce(): void
    {
        $migrations = (new MigrationLoader())->load(self::basePath() . '/tests/Fixtures/migrations');

        self::assertCount(2, $this->migrator->migrate($migrations));
        self::assertSame([], $this->migrator->migrate($migrations));

        $rows = $this->repository->fetchAll('SELECT display_name, note, balance_minor FROM example_accounts');
        self::assertSame([['display_name' => 'Fixture; Account', 'note' => 'semi;colon', 'balance_minor' => 0]], $rows);

        $recorded = $this->repository->fetchAll('SELECT version, checksum FROM schema_migrations ORDER BY version');
        self::assertSame(['0001', '0002'], array_column($recorded, 'version'));
        self::assertSame($migrations[0]->checksum, $recorded[0]['checksum'] ?? null);
    }

    public function testDetectsTamperingWithAnAppliedMigration(): void
    {
        $migrations = (new MigrationLoader())->load(self::basePath() . '/tests/Fixtures/migrations');
        $this->migrator->migrate($migrations);

        $this->expectException(MigrationError::class);
        $this->migrator->migrate([new Migration('0001', 'create_example_accounts', 'DROP TABLE example_accounts;'), $migrations[1]]);
    }

    public function testSecondRunnerIsBlockedByTheAdvisoryLock(): void
    {
        $other = self::pdo();
        $statement = $other->query("SELECT GET_LOCK('paxoficloud:migrate', 0) AS acquired");
        self::assertNotFalse($statement);
        self::assertSame(['acquired' => 1], $statement->fetch());

        try {
            $blocked = new Migrator(
                Resolve::get(AppFactory::create(self::basePath())->container(), Connection::class),
                $this->repository,
                new MysqlAdvisoryLock($this->repository, timeoutSeconds: 0),
            );
            $this->expectException(MigrationError::class);
            $this->expectExceptionMessage('holds the lock');
            $blocked->migrate((new MigrationLoader())->load(self::basePath() . '/tests/Fixtures/migrations'));
        } finally {
            $other->query("SELECT RELEASE_LOCK('paxoficloud:migrate')");
        }
    }
}
