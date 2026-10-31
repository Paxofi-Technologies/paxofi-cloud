<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Integration;

use Paxofi\Core\Contracts\Connection;
use Paxofi\Core\Contracts\Repository;
use PaxofiCloud\Application\Domains\DomainSearchSettings;
use PaxofiCloud\Application\Domains\LookupSettings;
use PaxofiCloud\Bootstrap\AppFactory;
use PaxofiCloud\Infrastructure\Container\Resolve;
use PaxofiCloud\Infrastructure\Domains\MysqlDomainSearchSettingsStore;
use PaxofiCloud\Infrastructure\Migrations\MigrationLoader;
use PaxofiCloud\Infrastructure\Migrations\Migrator;
use PaxofiCloud\Infrastructure\Migrations\MysqlAdvisoryLock;
use PDOException;

/** Migration 0030 and MysqlDomainSearchSettingsStore on MySQL 8.4 (THREAT-MODEL-DOMAIN-SEARCH.md DS-21 to DS-23). */
final class DomainSearchSettingsStoreIntegrationTest extends IntegrationTestCase
{
    private const string STAFF = 'a1000000000000000000000000000001';

    private MysqlDomainSearchSettingsStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        self::resetDatabase();
        $container = AppFactory::create(self::basePath())->container();
        $repository = Resolve::get($container, Repository::class);
        $connection = Resolve::get($container, Connection::class);
        (new Migrator($connection, $repository, new MysqlAdvisoryLock($repository)))->migrate((new MigrationLoader())->load(self::basePath() . '/migrations'));
        self::pdo()->prepare("INSERT INTO staff_members (id, email, email_lookup, full_name, status, created_at, webauthn_user_handle) VALUES (?, 'cat@example.test', ?, 'Cat', 'active', NOW(6), ?)")
            ->execute([self::STAFF, hash('sha256', 'cat@example.test'), random_bytes(32)]);
        $this->store = new MysqlDomainSearchSettingsStore($connection, $repository);
    }

    public function testDefaultsUntilSavedThenReadsBackAndUpdatesInPlace(): void
    {
        self::assertEquals(DomainSearchSettings::defaults(), $this->store->settings());
        $at = new \DateTimeImmutable('2026-10-31T12:00:00Z');

        $settings = new DomainSearchSettings(['com.ng', 'ng', 'com'], 15, ['get'], ['hq', 'ng']);
        $this->store->saveSettings($settings, self::STAFF, $at);
        self::assertEquals($settings, $this->store->settings());

        $empty = new DomainSearchSettings([], 0, [], []);
        $this->store->saveSettings($empty, self::STAFF, $at);
        self::assertEquals($empty, $this->store->settings(), 'an empty choice stays a choice');
        $count = self::pdo()->query('SELECT COUNT(*) FROM domain_search_settings');
        self::assertNotFalse($count);
        self::assertEquals(1, $count->fetchColumn());
    }

    public function testLookupsAreSavedPerExtensionAndUpdatedInPlace(): void
    {
        $at = new \DateTimeImmutable('2026-10-31T12:00:00Z');
        $this->store->saveLookup(new LookupSettings('ng', 'whois', 'whois.nic.net.ng', 'No Object Found'), self::STAFF, $at);
        $this->store->saveLookup(new LookupSettings('com', 'rdap'), self::STAFF, $at);
        $this->store->saveLookup(new LookupSettings('ng', 'auto', null, null), self::STAFF, $at);

        self::assertEquals(['com' => new LookupSettings('com', 'rdap'), 'ng' => new LookupSettings('ng')], $this->store->lookups());
    }

    public function testTheDatabaseRefusesAnUnknownModeOrAnIpAsServer(): void
    {
        foreach ([
            "INSERT INTO domain_lookup_settings (tld, mode, updated_by, updated_at) VALUES ('ng', 'fastest', '" . self::STAFF . "', NOW(6))",
            "INSERT INTO domain_lookup_settings (tld, mode, whois_server, updated_by, updated_at) VALUES ('ng', 'whois', '10.0.0.1:43', '" . self::STAFF . "', NOW(6))",
            "INSERT INTO domain_search_settings (id, search_tlds, max_ideas, idea_prefixes, idea_suffixes, updated_by, updated_at) VALUES (1, '[]', 31, '[]', '[]', '" . self::STAFF . "', NOW(6))",
        ] as $sql) {
            try {
                self::pdo()->exec($sql);
                self::fail('expected a check constraint failure: ' . $sql);
            } catch (PDOException $e) {
                self::assertStringContainsString('3819', (string) $e->getMessage());
            }
        }
    }
}
