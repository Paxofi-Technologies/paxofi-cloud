<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Integration;

use Paxofi\Core\Contracts\Connection;
use Paxofi\Core\Contracts\Repository;
use PaxofiCloud\Bootstrap\AppFactory;
use PaxofiCloud\Infrastructure\Container\Resolve;
use PaxofiCloud\Infrastructure\Identity\MysqlIdentityStore;
use PaxofiCloud\Infrastructure\Migrations\MigrationLoader;
use PaxofiCloud\Infrastructure\Migrations\Migrator;
use PaxofiCloud\Infrastructure\Migrations\MysqlAdvisoryLock;

/** Token rules enforced by the SQL itself, independent of the services (T-03). */
final class IdentityStoreIntegrationTest extends IntegrationTestCase
{
    private MysqlIdentityStore $store;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        self::resetDatabase();
        $app = AppFactory::create(self::basePath());
        $connection = Resolve::get($app->container(), Connection::class);
        $repository = Resolve::get($app->container(), Repository::class);
        (new Migrator($connection, $repository, new MysqlAdvisoryLock($repository)))->migrate((new MigrationLoader())->load(self::basePath() . '/migrations'));
        $this->store = new MysqlIdentityStore($connection, $repository);
        $this->now = new \DateTimeImmutable('2026-10-26 09:00:00 UTC');
        $this->store->createPending('a' . str_repeat('0', 31), 'b' . str_repeat('0', 31), 'ada@example.ng', hash('sha256', 'ada@example.ng'), 'Ada Okafor', '+2348031234567', '$argon2id$v=19$m=65536,t=3,p=1$x$y', '2026-10-01', $this->now);
    }

    public function testATokenCanBeConsumedExactlyOnce(): void
    {
        $id = 'a' . str_repeat('0', 31);
        $this->store->replaceVerificationToken($id, str_repeat('1', 64), $this->now->modify('+1 day'));

        self::assertSame($id, $this->store->consumeVerificationToken(str_repeat('1', 64), $this->now));
        self::assertNull($this->store->consumeVerificationToken(str_repeat('1', 64), $this->now->modify('+1 minute')));
    }

    public function testAnExpiredTokenCannotBeConsumed(): void
    {
        $this->store->replaceVerificationToken('a' . str_repeat('0', 31), str_repeat('2', 64), $this->now);

        self::assertNull($this->store->consumeVerificationToken(str_repeat('2', 64), $this->now));
    }

    public function testANewTokenRemovesOnlyUnusedOlderTokens(): void
    {
        $id = 'a' . str_repeat('0', 31);
        $this->store->replaceVerificationToken($id, str_repeat('3', 64), $this->now->modify('+1 day'));
        $this->store->consumeVerificationToken(str_repeat('3', 64), $this->now);
        $this->store->replaceVerificationToken($id, str_repeat('4', 64), $this->now->modify('+1 day'));
        $this->store->replaceVerificationToken($id, str_repeat('5', 64), $this->now->modify('+1 day'));

        $statement = self::pdo()->query('SELECT token_hash FROM email_verification_tokens ORDER BY token_hash');
        self::assertNotFalse($statement);
        $hashes = array_column($statement->fetchAll(), 'token_hash');
        self::assertSame([str_repeat('3', 64), str_repeat('5', 64)], $hashes, 'Used tokens stay as a record; unused older ones are gone');
    }
}
