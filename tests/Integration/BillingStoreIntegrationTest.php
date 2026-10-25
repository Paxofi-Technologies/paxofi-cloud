<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Integration;

use Paxofi\Core\Contracts\Connection;
use Paxofi\Core\Contracts\Repository;
use PaxofiCloud\Application\Billing\BillingDetails;
use PaxofiCloud\Bootstrap\AppFactory;
use PaxofiCloud\Domain\Money\Currency;
use PaxofiCloud\Infrastructure\Billing\MysqlBillingStore;
use PaxofiCloud\Infrastructure\Billing\MysqlTaxRateStore;
use PaxofiCloud\Infrastructure\Container\Resolve;
use PaxofiCloud\Infrastructure\Migrations\MigrationLoader;
use PaxofiCloud\Infrastructure\Migrations\Migrator;
use PaxofiCloud\Infrastructure\Migrations\MysqlAdvisoryLock;
use PDOException;

/** Migration 0023 with MysqlBillingStore and MysqlTaxRateStore on MySQL 8.4 (commerce CD-21, CD-22, C-40). */
final class BillingStoreIntegrationTest extends IntegrationTestCase
{
    private const string ACCOUNT = 'b1000000000000000000000000000001';
    private const string OWNER = 'b2000000000000000000000000000001';

    private MysqlBillingStore $store;
    private MysqlTaxRateStore $taxes;

    protected function setUp(): void
    {
        parent::setUp();
        self::resetDatabase();
        $container = AppFactory::create(self::basePath())->container();
        $repository = Resolve::get($container, Repository::class);
        $connection = Resolve::get($container, Connection::class);
        (new Migrator($connection, $repository, new MysqlAdvisoryLock($repository)))->migrate((new MigrationLoader())->load(self::basePath() . '/migrations'));
        $pdo = self::pdo();
        $pdo->prepare("INSERT INTO accounts (id, type, name) VALUES (?, 'personal', 'Test')")->execute([self::ACCOUNT]);
        $pdo->prepare("INSERT INTO identities (id, email, email_lookup, full_name, phone_e164, password_hash, status, terms_version, terms_accepted_at, verified_at)
            VALUES (?, 'owner@example.test', ?, 'Owner', '+2348000000000', '\$argon2id\$v=19\$m=65536,t=4,p=1\$x\$y', 'active', 'v1', NOW(6), NOW(6))")
            ->execute([self::OWNER, hash('sha256', 'owner@example.test')]);
        $pdo->prepare("INSERT INTO account_members (account_id, identity_id, role) VALUES (?, ?, 'owner')")->execute([self::ACCOUNT, self::OWNER]);
        $this->store = new MysqlBillingStore($connection, $repository);
        $this->taxes = new MysqlTaxRateStore($repository);
    }

    private static function details(string $country): BillingDetails
    {
        return BillingDetails::fromInput([
            'full_name' => 'Ada Test', 'company' => 'Test Ltd', 'address_line1' => '1 Test Street', 'address_line2' => '',
            'city' => 'Ikeja', 'region' => 'Lagos', 'postcode' => '', 'country' => $country, 'phone' => '+2348000000000', 'tin' => '',
        ]);
    }

    public function testSavesReadsAndKnowsTheOwner(): void
    {
        self::assertNull($this->store->find(self::ACCOUNT));
        $this->store->save(self::ACCOUNT, self::details('NG'), Currency::NGN, new \DateTimeImmutable('2026-10-26 09:00:00 UTC'));

        $found = $this->store->find(self::ACCOUNT);
        self::assertNotNull($found);
        self::assertSame('Test Ltd', $found->details->company);
        self::assertNull($found->details->postcode);
        self::assertSame(Currency::NGN, $found->currency);
        self::assertTrue($this->store->isOwner(self::OWNER, self::ACCOUNT));
        self::assertFalse($this->store->isOwner(self::OWNER, 'b1000000000000000000000000000002'));
    }

    public function testALockedCurrencyCannotBeChangedBySavingAgain(): void
    {
        $this->store->save(self::ACCOUNT, self::details('NG'), Currency::NGN, new \DateTimeImmutable('2026-10-26 09:00:00 UTC'));
        self::pdo()->exec("UPDATE account_billing_details SET currency_locked_at = NOW(6)");

        // Even a caller that passes USD cannot move a locked currency (the SQL guards it, C-40).
        $this->store->save(self::ACCOUNT, self::details('GH'), Currency::USD, new \DateTimeImmutable('2026-10-26 10:00:00 UTC'));

        $found = $this->store->find(self::ACCOUNT);
        self::assertNotNull($found);
        self::assertSame('GH', $found->details->country);
        self::assertSame(Currency::NGN, $found->currency);
        self::assertTrue($found->currencyLocked());
    }

    public function testChecksRefuseBadRows(): void
    {
        $this->expectException(PDOException::class);
        self::pdo()->prepare("INSERT INTO account_billing_details (account_id, full_name, address_line1, city, region, country, phone_e164, billing_currency, created_at, updated_at)
            VALUES (?, 'x', 'x', 'x', 'x', 'NG', '+2348000000000', 'EUR', NOW(6), NOW(6))")->execute([self::ACCOUNT]);
    }

    public function testTheLaunchTaxRowsAndFallback(): void
    {
        $at = new \DateTimeImmutable('2026-10-26 09:00:00 UTC');
        self::assertSame(['NG', 'VAT', 750], self::row($this->taxes->inForce('NG', $at)));
        self::assertSame(['US', 'Sales tax', 0], self::row($this->taxes->inForce('US', $at)));
        self::assertSame(['ZZ', 'Tax', 0], self::row($this->taxes->inForce('GH', $at)));

        // A future-dated row is not in force yet; once its time comes it replaces the old one (CD-22, append-only).
        self::pdo()->exec("INSERT INTO tax_rates (country, tax_name, rate_bp, effective_from, created_by) VALUES ('NG', 'VAT', 1000, '2027-01-01 00:00:00', 'test')");
        self::assertSame(750, $this->taxes->inForce('NG', $at)->rate->value);
        self::assertSame(1000, $this->taxes->inForce('NG', new \DateTimeImmutable('2027-01-02 00:00:00 UTC'))->rate->value);
    }

    /** @return array{string, string, int} */
    private static function row(\PaxofiCloud\Domain\Billing\TaxRate $rate): array
    {
        return [$rate->country, $rate->name, $rate->rate->value];
    }
}
