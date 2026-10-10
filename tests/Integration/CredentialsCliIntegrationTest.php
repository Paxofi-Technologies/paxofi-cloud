<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Integration;

use Paxofi\Core\Contracts\Connection;
use Paxofi\Core\Contracts\Repository;
use PaxofiCloud\Bootstrap\AppFactory;
use PaxofiCloud\Cli\CredentialsListCommand;
use PaxofiCloud\Cli\CredentialsReencryptCommand;
use PaxofiCloud\Cli\CredentialsSetCommand;
use PaxofiCloud\Cli\CredentialStoreAccess;
use PaxofiCloud\Infrastructure\Container\Resolve;
use PaxofiCloud\Infrastructure\Migrations\MigrationLoader;
use PaxofiCloud\Infrastructure\Migrations\Migrator;
use PaxofiCloud\Infrastructure\Migrations\MysqlAdvisoryLock;
use PaxofiCloud\Infrastructure\Secrets\KeyRing;
use PaxofiCloud\Infrastructure\Secrets\MysqlProviderCredentialStore;
use PaxofiCloud\Infrastructure\Secrets\SecretCipher;
use PaxofiCloud\Tests\Support\ConsoleStreams;
use PaxofiCloud\Tests\Support\FixedClock;

/** Operator CLI for provider credentials against real MySQL 8.4 (SRS SEC-004/SEC-005). */
final class CredentialsCliIntegrationTest extends IntegrationTestCase
{
    private const string SECRET = 'cli-token-not-real-0123456789abcdef';

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

    public function testSetStoresTheTokenFromStandardInputAndNeverPrintsIt(): void
    {
        $streams = new ConsoleStreams(self::SECRET . "\n");
        $exit = $this->set($streams, $this->oldKey, 'k2025', ['hetzner-prod-customers', 'hetzner', 'prod', 'read-write', '2027-01-05']);

        self::assertSame(0, $exit, $streams->errors());
        self::assertStringContainsString('Stored credential "hetzner-prod-customers"', $streams->output());
        self::assertStringNotContainsString(self::SECRET, $streams->output() . $streams->errors());
        self::assertSame(self::SECRET, $this->store($this->oldKey, 'k2025')->load('hetzner-prod-customers')->reveal());
    }

    public function testSetReplacesTheTokenButNotTheAccessLevel(): void
    {
        $this->set(new ConsoleStreams('first-token-value'), $this->oldKey, 'k2025', ['vultr-prod-customers', 'vultr', 'prod', 'read-write', '2027-01-05']);

        self::assertSame(0, $this->set(new ConsoleStreams('second-token-value'), $this->oldKey, 'k2025', ['vultr-prod-customers', 'vultr', 'prod', 'read-write', '2027-03-01']));
        self::assertSame('second-token-value', $this->store($this->oldKey, 'k2025')->load('vultr-prod-customers')->reveal());

        $escalate = new ConsoleStreams('third-token-value');
        self::assertSame(1, $this->set($escalate, $this->oldKey, 'k2025', ['vultr-prod-customers', 'vultr', 'prod', 'read-only', '2027-03-01']));
        self::assertStringContainsString('already exists', $escalate->errors());
        self::assertSame('second-token-value', $this->store($this->oldKey, 'k2025')->load('vultr-prod-customers')->reveal());
    }

    public function testListShowsMetadataOnlyAndFlagsOverdueReviewsAndOldKeys(): void
    {
        $this->set(new ConsoleStreams(self::SECRET), $this->oldKey, 'k2025', ['hetzner-prod-customers', 'hetzner', 'prod', 'read-write', '2027-01-05']);

        $ok = new ConsoleStreams();
        self::assertSame(0, (new CredentialsListCommand($this->access($this->oldKey, 'k2025'), $ok->console(), self::clock('2026-10-06')))->execute([]));
        self::assertMatchesRegularExpression('/hetzner-prod-customers\s+hetzner\s+prod\s+read-write\s+k2025\s+2027-01-05\s+ok/', $ok->output());
        self::assertStringNotContainsString(self::SECRET, $ok->output());
        self::assertStringNotContainsString('v1.k2025.', $ok->output(), 'Envelopes are never printed');

        $later = new ConsoleStreams();
        self::assertSame(2, (new CredentialsListCommand($this->access($this->newKey . ',' . $this->oldKey, 'k2026'), $later->console(), self::clock('2027-01-06')))->execute([]));
        self::assertStringContainsString('review overdue, old key', $later->output());
    }

    public function testReencryptMovesEveryCredentialToTheActiveKey(): void
    {
        $this->set(new ConsoleStreams(self::SECRET), $this->oldKey, 'k2025', ['hetzner-prod-customers', 'hetzner', 'prod', 'read-write', '2027-01-05']);
        $this->set(new ConsoleStreams('reconcile-token-value'), $this->oldKey, 'k2025', ['hetzner-prod-reconcile', 'hetzner', 'prod', 'read-only', '2027-01-05']);

        $rotate = new ConsoleStreams();
        self::assertSame(0, (new CredentialsReencryptCommand($this->access($this->newKey . ',' . $this->oldKey, 'k2026'), $rotate->console()))->execute([]));
        self::assertStringContainsString('Re-encrypted 2 credential(s).', $rotate->output());

        $again = new ConsoleStreams();
        self::assertSame(0, (new CredentialsReencryptCommand($this->access($this->newKey . ',' . $this->oldKey, 'k2026'), $again->console()))->execute([]));
        self::assertStringContainsString('Re-encrypted 0 credential(s).', $again->output());

        // The old key can now be retired.
        self::assertSame(self::SECRET, $this->store($this->newKey, 'k2026')->load('hetzner-prod-customers')->reveal());
    }

    public function testReencryptStopsWhenAnOldKeyIsMissingFromTheRing(): void
    {
        $this->set(new ConsoleStreams(self::SECRET), $this->oldKey, 'k2025', ['hetzner-prod-customers', 'hetzner', 'prod', 'read-write', '2027-01-05']);

        // Operator removed the old key too early.
        $rotate = new ConsoleStreams();
        self::assertSame(1, (new CredentialsReencryptCommand($this->access($this->newKey, 'k2026'), $rotate->console()))->execute([]));
        self::assertStringContainsString('Unknown key ID "k2025"', $rotate->errors());
    }

    /** @param list<string> $arguments */
    private function set(ConsoleStreams $streams, string $keys, string $active, array $arguments): int
    {
        return (new CredentialsSetCommand($this->access($keys, $active), $streams->console(), self::clock('2026-10-06')))->execute($arguments);
    }

    private function access(string $keys, string $active): CredentialStoreAccess
    {
        return new CredentialStoreAccess(fn (): MysqlProviderCredentialStore => $this->store($keys, $active));
    }

    private function store(string $keys, string $active): MysqlProviderCredentialStore
    {
        return new MysqlProviderCredentialStore($this->connection, $this->repository, new SecretCipher(KeyRing::fromEnvironment($keys, $active)));
    }

    private static function clock(string $date): FixedClock
    {
        return new FixedClock(new \DateTimeImmutable($date . 'T12:00:00+00:00'));
    }
}
