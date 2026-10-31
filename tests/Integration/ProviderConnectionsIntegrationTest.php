<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Integration;

use Paxofi\Core\Contracts\Connection;
use Paxofi\Core\Contracts\Logger;
use Paxofi\Core\Contracts\Repository;
use Paxofi\Core\Contracts\TransactionManager;
use PaxofiCloud\Bootstrap\AppFactory;
use PaxofiCloud\Infrastructure\Container\Resolve;
use PaxofiCloud\Infrastructure\Migrations\MigrationLoader;
use PaxofiCloud\Infrastructure\Migrations\Migrator;
use PaxofiCloud\Infrastructure\Migrations\MysqlAdvisoryLock;
use PaxofiCloud\Infrastructure\Secrets\CredentialIntakeWorker;
use PaxofiCloud\Infrastructure\Secrets\CredentialTable;
use PaxofiCloud\Infrastructure\Secrets\DecryptionFailed;
use PaxofiCloud\Infrastructure\Secrets\KeyRing;
use PaxofiCloud\Infrastructure\Secrets\MysqlCredentialIntake;
use PaxofiCloud\Infrastructure\Secrets\MysqlProviderCredentialStatus;
use PaxofiCloud\Infrastructure\Secrets\MysqlProviderCredentialStore;
use PaxofiCloud\Infrastructure\Secrets\SealedBox;
use PaxofiCloud\Infrastructure\Secrets\SecretCipher;
use PaxofiCloud\Infrastructure\Secrets\StoredCredential;
use PaxofiCloud\Tests\Support\AuditFixtures;
use PaxofiCloud\Tests\Support\InMemoryStaffDirectory;
use PaxofiCloud\Tests\Support\MutableClock;
use PaxofiCloud\Tests\Support\RecordingOutbox;
use PDOException;

/** Migrations 0012 and 0026 and the intake → worker → encrypted store path on MySQL 8.4 (PC-1, PC-5, PY-3). */
final class ProviderConnectionsIntegrationTest extends IntegrationTestCase
{
    private const string STAFF = 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1';
    private const string SECRET = 'integration-token-not-real';

    private Connection $connection;
    private Repository $repository;
    private MysqlCredentialIntake $intake;

    protected function setUp(): void
    {
        parent::setUp();
        self::resetDatabase();
        $container = AppFactory::create(self::basePath())->container();
        $this->connection = Resolve::get($container, Connection::class);
        $this->repository = Resolve::get($container, Repository::class);
        (new Migrator($this->connection, $this->repository, new MysqlAdvisoryLock($this->repository)))
            ->migrate((new MigrationLoader())->load(self::basePath() . '/migrations'));
        $this->intake = new MysqlCredentialIntake($this->connection, $this->repository, Resolve::get($container, TransactionManager::class));
    }

    public function testASealedSubmissionEndsUpEncryptedUnderTheKek(): void
    {
        $keys = SealedBox::generateKeyPair();
        $sealed = SealedBox::forSealing($keys['public'])->seal((string) json_encode([
            'v' => 1, 'credential_id' => 'vultr-dev', 'provider' => 'vultr', 'environment' => 'dev',
            'access_level' => 'read-write', 'secret' => self::SECRET, 'submitted_by' => self::STAFF,
        ]));
        $this->intake->replace('vultr-dev', 'vultr', $sealed, self::STAFF, new \DateTimeImmutable('2026-10-20T10:00:00Z'));
        self::assertArrayHasKey('vultr-dev', $this->intake->pending());

        $store = new MysqlProviderCredentialStore($this->connection, $this->repository, new SecretCipher(KeyRing::fromEnvironment(KeyRing::generateEntry('k1'), 'k1')));
        $worker = new CredentialIntakeWorker(
            'dev',
            $this->intake,
            SealedBox::forOpening($keys['public'], $keys['secret']),
            $store,
            new InMemoryStaffDirectory(),
            new RecordingOutbox(),
            AuditFixtures::trail(),
            new MutableClock(new \DateTimeImmutable('2026-10-20T10:00:05Z')),
            Resolve::get(AppFactory::create(self::basePath())->container(), Logger::class),
        );

        self::assertSame(1, $worker->runOnce());
        self::assertSame([], $this->intake->pending(), 'the sealed row is gone');
        self::assertSame(self::SECRET, $store->load('vultr-dev')->reveal());
        self::assertArrayHasKey('vultr-dev', (new MysqlProviderCredentialStatus($this->repository))->configured());

        $dump = self::pdo()->query('SELECT envelope FROM provider_credentials');
        self::assertNotFalse($dump);
        self::assertStringNotContainsString(self::SECRET, (string) json_encode($dump->fetchAll()));
    }

    public function testGatewayKeysGoOnlyThroughThePaymentsLaneIntoTheirOwnTable(): void
    {
        $providerKeys = SealedBox::generateKeyPair();
        $paymentKeys = SealedBox::generateKeyPair();
        $secret = '{"secret_key":"integration-gateway-key-not-real"}';
        $this->intake->replace('paystack-dev', 'paystack', SealedBox::forSealing($paymentKeys['public'], 'PAYMENTS_INTAKE')->seal((string) json_encode([
            'v' => 1, 'credential_id' => 'paystack-dev', 'provider' => 'paystack', 'environment' => 'dev',
            'access_level' => 'read-write', 'secret' => $secret, 'submitted_by' => self::STAFF,
        ])), self::STAFF, new \DateTimeImmutable('2026-10-20T10:00:00Z'));
        $this->intake->replace('vultr-dev', 'vultr', str_repeat('A', 80), self::STAFF, new \DateTimeImmutable('2026-10-20T10:00:01Z'));

        self::assertSame(['vultr-dev'], array_column($this->intake->oldest(10), 'credential_id'), 'provider lane');
        self::assertSame(['paystack-dev'], array_column($this->intake->oldest(10, true), 'credential_id'), 'payments lane');

        $providerRing = new SecretCipher(KeyRing::fromEnvironment(KeyRing::generateEntry('k1'), 'k1'));
        $paymentsStore = new MysqlProviderCredentialStore($this->connection, $this->repository, new SecretCipher(KeyRing::fromEnvironment(KeyRing::generateEntry('k1'), 'k1')), CredentialTable::PaymentGateways);
        $worker = new CredentialIntakeWorker(
            'dev',
            $this->intake,
            SealedBox::forOpening($paymentKeys['public'], $paymentKeys['secret'], 'PAYMENTS_INTAKE'),
            $paymentsStore,
            new InMemoryStaffDirectory(),
            new RecordingOutbox(),
            AuditFixtures::trail(),
            new MutableClock(new \DateTimeImmutable('2026-10-20T10:00:05Z')),
            Resolve::get(AppFactory::create(self::basePath())->container(), Logger::class),
            paymentGateways: true,
        );

        self::assertSame(1, $worker->runOnce());
        self::assertSame($secret, $paymentsStore->load('paystack-dev')->reveal());
        self::assertSame(['vultr-dev'], array_column($this->intake->oldest(10), 'credential_id'), 'the provider row is untouched');
        self::assertEquals(0, self::scalar("SELECT COUNT(*) FROM provider_credentials WHERE provider = 'paystack'"));
        self::assertArrayHasKey('paystack-dev', (new MysqlProviderCredentialStatus($this->repository))->configured(), 'the admin overview sees both tables');

        // The provider key ring cannot read the gateway row, and the provider store never lists it.
        $row = self::scalar("SELECT envelope FROM payment_gateway_credentials WHERE id = 'paystack-dev'");
        self::assertIsString($row);
        self::assertStringNotContainsString('integration-gateway-key', $row);
        $this->expectException(DecryptionFailed::class);
        $providerRing->decrypt($row, (new StoredCredential('paystack-dev', 'paystack', 'dev', 'read-write'))->associatedData());
    }

    public function testThePaymentGatewayTableRefusesOtherProviders(): void
    {
        $this->expectException(PDOException::class);
        self::pdo()->exec("INSERT INTO payment_gateway_credentials (id, provider, environment, access_level, envelope, key_id, review_due_on) VALUES ('vultr-dev', 'vultr', 'dev', 'read-write', 'x', 'k1', '2027-01-01')");
    }

    public function testRemovingKeepsANewerSubmission(): void
    {
        $this->intake->replace('hetzner-dev', 'hetzner', str_repeat('A', 80), self::STAFF, new \DateTimeImmutable());
        $this->intake->replace('hetzner-dev', 'hetzner', str_repeat('B', 80), self::STAFF, new \DateTimeImmutable());

        $this->intake->remove('hetzner-dev', str_repeat('A', 80));

        self::assertSame(str_repeat('B', 80), $this->intake->oldest(5)[0]['sealed'] ?? null);
    }

    public function testTheDatabaseRefusesMalformedIntakeRows(): void
    {
        // Migrations 0017, 0018 and 0019: Namecheap, Dynadot and Spaceship submissions are accepted alongside the original providers.
        foreach (['namecheap-dev' => 'namecheap', 'dynadot-dev' => 'dynadot', 'spaceship-dev' => 'spaceship'] as $credentialId => $provider) {
            self::pdo()->prepare('INSERT INTO provider_credential_intake (credential_id, provider, sealed, submitted_by, submitted_at) VALUES (?, ?, ?, ?, NOW(6))')
                ->execute([$credentialId, $provider, str_repeat('A', 80), self::STAFF]);
        }

        foreach ([
            ['hetzner-dev', 'aws', str_repeat('A', 80), self::STAFF],
            ['Hetzner_dev', 'hetzner', str_repeat('A', 80), self::STAFF],
            ['hetzner-dev', 'hetzner', 'short', self::STAFF],
            ['hetzner-dev', 'hetzner', str_repeat('A', 79) . '=', self::STAFF],
            ['hetzner-dev', 'hetzner', str_repeat('A', 80), 'not-a-staff-id'],
        ] as $row) {
            try {
                self::pdo()->prepare('INSERT INTO provider_credential_intake (credential_id, provider, sealed, submitted_by, submitted_at) VALUES (?, ?, ?, ?, NOW(6))')->execute($row);
                self::fail('malformed intake row stored: ' . $row[0] . ' ' . $row[1]);
            } catch (PDOException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private static function scalar(string $sql): mixed
    {
        $statement = self::pdo()->query($sql);
        self::assertNotFalse($statement);

        return $statement->fetchColumn();
    }
}
