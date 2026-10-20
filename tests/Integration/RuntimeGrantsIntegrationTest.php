<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Integration;

use PaxofiCloud\Infrastructure\Database\GrantsRefused;
use PaxofiCloud\Infrastructure\Database\RuntimeGrants;
use PaxofiCloud\Infrastructure\Migrations\MigrationLoader;
use PaxofiCloud\Infrastructure\Migrations\Migrator;
use PaxofiCloud\Infrastructure\Migrations\MysqlAdvisoryLock;
use Paxofi\Core\Persistence\PdoConnection;
use Paxofi\Core\Persistence\PdoRepository;
use PDO;
use PDOException;

/**
 * §6.22 / D-14: the runtime MySQL user cannot change or delete audit events,
 * cannot read migration bookkeeping and cannot run DDL. Needs DB_ROOT_PASSWORD
 * (test-only) to create the throwaway runtime user, as the MySQL init script
 * does on a real host.
 */
final class RuntimeGrantsIntegrationTest extends IntegrationTestCase
{
    private const string USER = 'pc_itest_app';
    private const string PASSWORD = 'itest-runtime-only';

    protected function setUp(): void
    {
        parent::setUp();
        self::resetDatabase();
        $root = self::root();
        $root->exec("DROP USER IF EXISTS '" . self::USER . "'@'%'");
        $root->exec("CREATE USER '" . self::USER . "'@'%' IDENTIFIED BY '" . self::PASSWORD . "'");
        // The init script gives the owner GRANT OPTION on its own schema only.
        $root->exec("GRANT ALL PRIVILEGES ON `paxoficloud`.* TO '" . self::env('DB_USERNAME') . "'@'%' WITH GRANT OPTION");
        [$connection, $repository] = self::owner();
        (new Migrator($connection, $repository, new MysqlAdvisoryLock($repository)))->migrate((new MigrationLoader())->load(self::basePath() . '/migrations'));
    }

    protected function tearDown(): void
    {
        if (getenv('PAXOFICLOUD_INTEGRATION') === '1') {
            self::root()->exec("DROP USER IF EXISTS '" . self::USER . "'@'%'");
            self::pdo()->exec('DROP TABLE IF EXISTS pc_itest_unlisted');
        }
        parent::tearDown();
    }

    public function testTheRuntimeUserCanAppendButNeverRewriteTheAuditLog(): void
    {
        [$connection, $repository] = self::owner();
        $result = (new RuntimeGrants($connection, $repository))->apply(self::USER);
        self::assertSame(count(RuntimeGrants::POLICY), $result['tables']);

        $app = self::runtime();
        $app->exec("INSERT INTO audit_events (seq, id, occurred_at, event_type, outcome, request_id, details, prev_hash, hash)
                    VALUES (1, REPEAT('a', 32), NOW(6), 'test.event', 'success', 'req-1', '{}', REPEAT('0', 64), REPEAT('1', 64))");
        self::assertEquals(1, self::scalar($app, 'SELECT COUNT(*) FROM audit_events'));
        $app->exec("UPDATE audit_chain_head SET seq = 1, hash = REPEAT('1', 64) WHERE id = 1");

        self::assertDenied($app, "UPDATE audit_events SET outcome = 'failure' WHERE seq = 1");
        self::assertDenied($app, 'DELETE FROM audit_events WHERE seq = 1');
        self::assertDenied($app, 'DELETE FROM audit_chain_head WHERE id = 1');
        self::assertDenied($app, 'SELECT * FROM schema_migrations');
        self::assertDenied($app, 'CREATE TABLE pc_itest_runtime_ddl (id INT)');
        self::assertDenied($app, 'DROP TABLE identities');
        self::assertSame('test.event', self::scalar(self::pdo(), 'SELECT event_type FROM audit_events WHERE seq = 1'), 'the event is unchanged');

        // Ordinary tables keep full row access.
        self::scalar($app, 'SELECT COUNT(*) FROM identities');
        $app->exec("DELETE FROM email_outbox WHERE id = 'none'");
    }

    public function testATableWithoutAPolicyStopsTheRelease(): void
    {
        self::pdo()->exec('CREATE TABLE pc_itest_unlisted (id INT PRIMARY KEY)');
        [$connection, $repository] = self::owner();

        $this->expectException(GrantsRefused::class);
        $this->expectExceptionMessage('pc_itest_unlisted');
        (new RuntimeGrants($connection, $repository))->apply(self::USER);
    }

    public function testReapplyingRemovesPrivilegesGrantedOutsideThePolicy(): void
    {
        self::root()->exec("GRANT UPDATE, DELETE ON `paxoficloud`.`audit_events` TO '" . self::USER . "'@'%'");
        [$connection, $repository] = self::owner();
        (new RuntimeGrants($connection, $repository))->apply(self::USER);

        self::assertDenied(self::runtime(), 'DELETE FROM audit_events');
    }

    public function testInvalidUserNamesAreRefused(): void
    {
        [$connection, $repository] = self::owner();
        $this->expectException(GrantsRefused::class);
        (new RuntimeGrants($connection, $repository))->apply("x'@'%' WITH GRANT OPTION --");
    }

    private static function assertDenied(PDO $pdo, string $sql): void
    {
        try {
            $pdo->exec($sql);
            self::fail('Runtime user was allowed: ' . $sql);
        } catch (PDOException $e) {
            $code = $e->errorInfo[1] ?? null;
            self::assertContains(is_int($code) ? $code : 0, [1142, 1044], $sql . ' → ' . $e->getMessage());
        }
    }

    private static function scalar(PDO $pdo, string $sql): mixed
    {
        $statement = $pdo->query($sql);
        self::assertNotFalse($statement);

        return $statement->fetchColumn();
    }

    /** @return array{PdoConnection, PdoRepository} */
    private static function owner(): array
    {
        $pdo = self::pdo();

        return [new PdoConnection($pdo), new PdoRepository($pdo)];
    }

    private static function runtime(): PDO
    {
        return new PDO(self::env('DB_DSN'), self::USER, self::PASSWORD, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
    }

    private static function root(): PDO
    {
        return new PDO(self::env('DB_DSN'), 'root', self::env('DB_ROOT_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }
}
