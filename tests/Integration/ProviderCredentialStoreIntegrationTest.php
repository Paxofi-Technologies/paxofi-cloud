<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Integration;

use Paxofi\Core\Contracts\Connection;
use Paxofi\Core\Contracts\Repository;
use PaxofiCloud\Bootstrap\AppFactory;
use PaxofiCloud\Infrastructure\Container\Resolve;
use PaxofiCloud\Infrastructure\Migrations\MigrationLoader;
use PaxofiCloud\Infrastructure\Migrations\Migrator;
use PaxofiCloud\Infrastructure\Migrations\MysqlAdvisoryLock;
use PaxofiCloud\Infrastructure\Secrets\DecryptionFailed;
use PaxofiCloud\Infrastructure\Secrets\KeyRing;
use PaxofiCloud\Infrastructure\Secrets\MysqlProviderCredentialStore;
use PaxofiCloud\Infrastructure\Secrets\SecretCipher;
use PaxofiCloud\Infrastructure\Secrets\StoredCredential;

/** Encrypted provider credentials against real MySQL 8.4 (threat model V-01, V-04). */
final class ProviderCredentialStoreIntegrationTest extends IntegrationTestCase
{
    private const string SECRET = 'test-token-not-real-0123456789abcdef';

    private Connection $connection;
    private Repository $repository;
    private string $oldKey;
    private string $newKey;

    protected function setUp(): void
    {
        parent::setUp();
        self::resetDatabase();

        $container = AppFactory::create(self::basePath())->container();
        $this->connection = Resolve::get($container, Connection::class);
        $this->repository = Resolve::get($container, Repository::class);
        (new Migrator($this->connection, $this->repository, new MysqlAdvisoryLock($this->repository)))
            ->migrate((new MigrationLoader())->load(self::basePath() . '/migrations'));

        $this->oldKey = KeyRing::generateEntry('k2025');
        $this->newKey = KeyRing::generateEntry('k2026');
    }

    protected function tearDown(): void
    {
        if (getenv('PAXOFICLOUD_INTEGRATION') === '1') {
            self::resetDatabase();
        }
    }

    public function testStoresOnlyCiphertextAndLoadsTheCredential(): void
    {
        $store = $this->store($this->oldKey, 'k2025');
        $store->save(self::credential('hetzner-prod-customers', 'read-write'), self::SECRET, new \DateTimeImmutable('2027-01-05'));

        $raw = self::pdo()->query("SELECT envelope, key_id FROM provider_credentials WHERE id = 'hetzner-prod-customers'");
        self::assertNotFalse($raw);
        $row = $raw->fetch();
        self::assertIsArray($row);
        self::assertIsString($row['envelope']);
        self::assertStringNotContainsString(self::SECRET, $row['envelope']);
        self::assertSame('k2025', $row['key_id']);

        $loaded = $store->load('hetzner-prod-customers');
        self::assertSame(self::SECRET, $loaded->reveal());
        self::assertSame('hetzner-prod-customers', $loaded->credentialId);
    }

    public function testCiphertextCopiedFromAReadOnlyRowCannotBeUsedAsReadWrite(): void
    {
        $store = $this->store($this->oldKey, 'k2025');
        $store->save(self::credential('hetzner-prod-reconcile', 'read-only'), 'read-only-token-example', new \DateTimeImmutable('2027-01-05'));
        $store->save(self::credential('hetzner-prod-customers', 'read-write'), self::SECRET, new \DateTimeImmutable('2027-01-05'));

        // An attacker with SQL write access swaps envelopes between rows.
        self::pdo()->exec("UPDATE provider_credentials SET envelope = (SELECT e FROM (SELECT envelope AS e FROM provider_credentials WHERE id = 'hetzner-prod-reconcile') t) WHERE id = 'hetzner-prod-customers'");

        $this->expectException(DecryptionFailed::class);
        $store->load('hetzner-prod-customers');
    }

    public function testRotationReencryptsEveryRowWithTheActiveKey(): void
    {
        $before = $this->store($this->oldKey, 'k2025');
        $before->save(self::credential('hetzner-prod-customers', 'read-write'), self::SECRET, new \DateTimeImmutable('2027-01-05'));
        $before->save(self::credential('vultr-prod-customers', 'read-write'), 'vultr-token-example', new \DateTimeImmutable('2027-01-05'));

        $after = $this->store($this->newKey . ',' . $this->oldKey, 'k2026');
        self::assertSame(2, $after->reencryptAll());
        self::assertSame(0, $after->reencryptAll(), 'A second run has nothing left to do');

        $keyIds = $this->repository->fetchAll('SELECT DISTINCT key_id FROM provider_credentials');
        self::assertSame([['key_id' => 'k2026']], $keyIds);

        // The old key can now be removed from the environment.
        $newOnly = $this->store($this->newKey, 'k2026');
        self::assertSame(self::SECRET, $newOnly->load('hetzner-prod-customers')->reveal());
        self::assertSame('vultr-token-example', $newOnly->load('vultr-prod-customers')->reveal());
    }

    public function testMetadataOfAnExistingCredentialCannotBeChanged(): void
    {
        $store = $this->store($this->oldKey, 'k2025');
        $store->save(self::credential('hetzner-prod-reconcile', 'read-only'), 'read-only-token-example', new \DateTimeImmutable('2027-01-05'));

        $this->expectException(\InvalidArgumentException::class);
        $store->save(self::credential('hetzner-prod-reconcile', 'read-write'), self::SECRET, new \DateTimeImmutable('2027-01-05'));
    }

    public function testUnknownCredentialIsAnError(): void
    {
        $this->expectException(\OutOfBoundsException::class);

        $this->store($this->oldKey, 'k2025')->load('missing');
    }

    private function store(string $keys, string $active): MysqlProviderCredentialStore
    {
        return new MysqlProviderCredentialStore($this->connection, $this->repository, new SecretCipher(KeyRing::fromEnvironment($keys, $active)));
    }

    private static function credential(string $id, string $access): StoredCredential
    {
        return new StoredCredential($id, explode('-', $id)[0], 'prod', $access);
    }
}
