<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Integration;

use Paxofi\Core\Contracts\Connection;
use Paxofi\Core\Contracts\Repository;
use PaxofiCloud\Application\Extensions\ExtensionSettings;
use PaxofiCloud\Bootstrap\AppFactory;
use PaxofiCloud\Domain\Money\BasisPoints;
use PaxofiCloud\Domain\Pricing\DomainPricingSettings;
use PaxofiCloud\Domain\Pricing\Markup;
use PaxofiCloud\Domain\Pricing\RoundingStep;
use PaxofiCloud\Domain\Registrar\TldName;
use PaxofiCloud\Infrastructure\Container\Resolve;
use PaxofiCloud\Infrastructure\Extensions\MysqlExtensionSettingsStore;
use PaxofiCloud\Infrastructure\Migrations\MigrationLoader;
use PaxofiCloud\Infrastructure\Migrations\Migrator;
use PaxofiCloud\Infrastructure\Migrations\MysqlAdvisoryLock;
use PDOException;

/** Migration 0020 and MysqlExtensionSettingsStore on MySQL 8.4 (THREAT-MODEL-DOMAIN-PRICING.md DX-5, DX-9, DX-10). */
final class ExtensionSettingsStoreIntegrationTest extends IntegrationTestCase
{
    private const string STAFF = 'a1000000000000000000000000000001';

    private MysqlExtensionSettingsStore $store;

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
        $this->store = new MysqlExtensionSettingsStore($connection, $repository);
    }

    public function testTheLaunchExtensionsAreSeededAsFeatured(): void
    {
        $all = $this->store->all();
        self::assertEquals(['ng' => 1, 'com.ng' => 2, 'com' => 3, 'org' => 4, 'site' => 5], array_map(static fn (ExtensionSettings $s): ?int => $s->featuredOrder, $all));
        self::assertNull($this->store->pricing(), 'no margins until staff enter them');
    }

    public function testSavesAndReadsBackSettingsAndPricing(): void
    {
        $at = new \DateTimeImmutable('2026-10-25T12:00:00Z');
        $settings = new ExtensionSettings(new TldName('com'), 'dynadot', 3, 'sale', 40, 0, 30, 8000, 'business', ['usd' => ['register' => 1199], 'ngn' => ['renew' => 1_700_000]]);
        $this->store->save($settings, self::STAFF, $at);
        $this->store->save(new ExtensionSettings(new TldName('xyz'), label: 'new'), self::STAFF, $at);
        $this->store->save(new ExtensionSettings(new TldName('online'), label: 'promo'), self::STAFF, $at);
        self::assertEquals($settings, $this->store->all()['com']);
        self::assertSame('new', $this->store->all()['xyz']->label);
        self::assertSame('promo', $this->store->all()['online']->label, 'migration 0022 allows PROMO');

        $pricing = new DomainPricingSettings(new Markup(2500), new BasisPoints(1000), new RoundingStep(1), new RoundingStep(5000));
        $this->store->savePricing($pricing, self::STAFF, $at);
        $this->store->savePricing($pricing, self::STAFF, $at);
        self::assertEquals($pricing, $this->store->pricing());
    }

    public function testTheTablesRefuseOutOfRangeValues(): void
    {
        foreach ([
            "UPDATE domain_extension_settings SET pinned_registrar = 'evil' WHERE tld = 'ng'",
            "UPDATE domain_extension_settings SET label = 'free' WHERE tld = 'ng'",
            "UPDATE domain_extension_settings SET grace_days = 91 WHERE tld = 'ng'",
            "UPDATE domain_extension_settings SET category = 'popular' WHERE tld = 'ng'",
            "UPDATE domain_extension_settings SET usd_register_minor = 0 WHERE tld = 'ng'",
            "UPDATE domain_extension_settings SET ngn_transfer_minor = 10000000001 WHERE tld = 'ng'",
            "INSERT INTO domain_extension_settings (tld, updated_at) VALUES ('BAD..TLD', NOW(6))",
            "INSERT INTO domain_pricing_settings VALUES (1, 500, 1000, 1, 1, '" . self::STAFF . "', NOW(6))",
            "INSERT INTO domain_pricing_settings VALUES (2, 2500, 1000, 1, 1, '" . self::STAFF . "', NOW(6))",
            "INSERT INTO domain_pricing_settings VALUES (1, 2500, 1000, 0, 1, '" . self::STAFF . "', NOW(6))",
        ] as $sql) {
            try {
                self::pdo()->exec($sql);
                self::fail('expected the CHECK constraint to refuse: ' . $sql);
            } catch (PDOException) {
            }
        }
        self::assertSame(5, (int) self::scalarCount());
    }

    private static function scalarCount(): int
    {
        $statement = self::pdo()->query('SELECT COUNT(*) FROM domain_extension_settings');
        self::assertNotFalse($statement);

        return (int) $statement->fetchColumn();
    }
}
