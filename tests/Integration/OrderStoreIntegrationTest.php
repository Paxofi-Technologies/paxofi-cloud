<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Integration;

use Paxofi\Core\Contracts\Connection;
use Paxofi\Core\Contracts\Repository;
use Paxofi\Core\Contracts\TransactionManager;
use PaxofiCloud\Application\Billing\BillingDetails;
use PaxofiCloud\Application\Checkout\NewOrder;
use PaxofiCloud\Application\Checkout\NewOrderLine;
use PaxofiCloud\Application\Contracts\DuplicateIdempotencyKey;
use PaxofiCloud\Bootstrap\AppFactory;
use PaxofiCloud\Domain\Billing\TaxRate;
use PaxofiCloud\Domain\Ledger\LedgerAccount;
use PaxofiCloud\Domain\Money\BasisPoints;
use PaxofiCloud\Domain\Money\Currency;
use PaxofiCloud\Infrastructure\Billing\MysqlBillingStore;
use PaxofiCloud\Infrastructure\Container\Resolve;
use PaxofiCloud\Infrastructure\Ledger\MysqlLedger;
use PaxofiCloud\Infrastructure\Migrations\MigrationLoader;
use PaxofiCloud\Infrastructure\Migrations\Migrator;
use PaxofiCloud\Infrastructure\Migrations\MysqlAdvisoryLock;
use PaxofiCloud\Infrastructure\Orders\MysqlOrderStore;
use PDOException;

/** Migration 0024 and MysqlOrderStore on MySQL 8.4 (commerce CD-24, CD-26, C-17, C-21, C-43, C-44; INV-002). */
final class OrderStoreIntegrationTest extends IntegrationTestCase
{
    private const string ALICE = 'c1000000000000000000000000000001';
    private const string ALICE_ID = 'c2000000000000000000000000000001';
    private const string BOB = 'c1000000000000000000000000000002';

    private MysqlOrderStore $orders;
    private MysqlBillingStore $billing;
    private MysqlLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        self::resetDatabase();
        $container = AppFactory::create(self::basePath())->container();
        $repository = Resolve::get($container, Repository::class);
        $connection = Resolve::get($container, Connection::class);
        (new Migrator($connection, $repository, new MysqlAdvisoryLock($repository)))->migrate((new MigrationLoader())->load(self::basePath() . '/migrations'));
        $pdo = self::pdo();
        foreach ([self::ALICE, self::BOB] as $account) {
            $pdo->prepare("INSERT INTO accounts (id, type, name) VALUES (?, 'personal', 'Test')")->execute([$account]);
        }
        $pdo->prepare("INSERT INTO identities (id, email, email_lookup, full_name, phone_e164, password_hash, status, terms_version, terms_accepted_at, verified_at)
            VALUES (?, 'alice@example.test', ?, 'Alice', '+2348000000000', '\$argon2id\$v=19\$m=65536,t=4,p=1\$x\$y', 'active', 'v1', NOW(6), NOW(6))")
            ->execute([self::ALICE_ID, hash('sha256', 'alice@example.test')]);
        $this->ledger = new MysqlLedger($connection, $repository, Resolve::get($container, TransactionManager::class));
        $this->orders = new MysqlOrderStore($connection, $repository, Resolve::get($container, TransactionManager::class), $this->ledger);
        $this->billing = new MysqlBillingStore($connection, $repository);
        $this->billing->save(self::ALICE, self::details(), Currency::NGN, new \DateTimeImmutable('2026-10-25 11:00:00 UTC'));
    }

    private static function details(): BillingDetails
    {
        return BillingDetails::fromInput([
            'full_name' => 'Alice Test', 'address_line1' => '1 Test Street', 'city' => 'Ikeja', 'region' => 'Lagos', 'country' => 'NG', 'phone' => '+2348000000000',
        ]);
    }

    private static function order(string $key, string $domain = 'paxofi-test.com'): NewOrder
    {
        $at = new \DateTimeImmutable('2026-10-25 12:00:00 UTC');

        return new NewOrder(
            bin2hex(random_bytes(16)),
            bin2hex(random_bytes(16)),
            self::ALICE,
            self::ALICE_ID,
            Currency::NGN,
            new TaxRate('NG', 'VAT', new BasisPoints(750)),
            self::details(),
            'NG',
            false,
            '2026-10-01',
            $key,
            hash('sha256', $key),
            $at,
            $at->modify('+72 hours'),
            [new NewOrderLine($domain, 1_650_000, 123_750, 1_815_000, 'domainnameapi', 1000, 'USD', str_repeat('f', 32))],
        );
    }

    public function testPlacesOrderAndInvoiceWithSequentialNumbersAndLocksTheCurrency(): void
    {
        $first = $this->orders->place(self::order('key-0000000000000001'));
        $second = $this->orders->place(self::order('key-0000000000000002', 'paxofi-test.ng'));

        self::assertSame(['order_number' => 'PXO-2026-000001', 'invoice_number' => 'PXC-2026-000001'], $first);
        self::assertSame(['order_number' => 'PXO-2026-000002', 'invoice_number' => 'PXC-2026-000002'], $second);
        self::assertTrue($this->billing->find(self::ALICE)?->currencyLocked(), 'the first invoice fixes the currency (PRC-003)');

        $list = $this->orders->forAccount(self::ALICE, 10, 0);
        self::assertCount(2, $list);
        self::assertSame(['PXO-2026-000002', 'PXO-2026-000001'], array_column($list, 'number'), 'newest first; ties broken by the sequential number');
        $order = $this->orders->find(self::ALICE, $list[1]['id']);
        self::assertNotNull($order);
        self::assertSame('pending_payment', $order['status']);
        self::assertSame('unpaid', $order['invoice_status']);
        self::assertSame(1_773_750, $order['total']);
        self::assertSame([['domain' => 'paxofi-test.com', 'years' => 1, 'price' => 1_650_000, 'tax' => 123_750, 'total' => 1_773_750, 'renewal_price' => 1_815_000]], $order['lines']);
        self::assertSame('Alice Test', self::column("SELECT bill_to_name FROM invoices WHERE order_id = '{$order['id']}'"), 'the invoice keeps a billing snapshot');

        // PY-13: each invoice posted its issue entry in the same transaction.
        self::assertSame(2 * 1_773_750, $this->ledger->balance(LedgerAccount::Receivable, Currency::NGN, self::ALICE));
        self::assertSame(-2 * 1_650_000, $this->ledger->balance(LedgerAccount::RevenueDomains, Currency::NGN));
        self::assertSame(-2 * 123_750, $this->ledger->balance(LedgerAccount::TaxPayable, Currency::NGN));
        self::assertSame(0, $this->ledger->balance(LedgerAccount::Receivable, Currency::NGN, self::BOB));
        self::assertTrue($this->ledger->verify()->ok());
    }

    public function testAnotherAccountCannotReadTheOrder(): void
    {
        $this->orders->place(self::order('key-0000000000000001'));
        $id = $this->orders->forAccount(self::ALICE, 1, 0)[0]['id'];

        self::assertNull($this->orders->find(self::BOB, $id));
        self::assertSame([], $this->orders->forAccount(self::BOB, 10, 0));
    }

    public function testAReusedKeyRollsBackSoNumbersStayGapFree(): void
    {
        $this->orders->place(self::order('key-0000000000000001'));
        try {
            $this->orders->place(self::order('key-0000000000000001', 'paxofi-test.ng'));
            self::fail('the key is unique per account');
        } catch (DuplicateIdempotencyKey) {
        }
        self::assertSame(['id' => $this->orders->forAccount(self::ALICE, 1, 0)[0]['id'], 'request_hash' => hash('sha256', 'key-0000000000000001')], $this->orders->findByIdempotencyKey(self::ALICE, 'key-0000000000000001'));

        // INV-002: the failed transaction returned its numbers.
        self::assertSame('PXC-2026-000002', $this->orders->place(self::order('key-0000000000000003'))['invoice_number']);
        self::assertEquals(2, self::column('SELECT COUNT(*) FROM invoices'));
        self::assertEquals(2, self::column('SELECT COUNT(*) FROM journal_entries'), 'the rolled-back order left no ledger entry');
        self::assertTrue($this->ledger->verify()->ok());
    }

    public function testChecksRefuseInconsistentTotals(): void
    {
        $this->orders->place(self::order('key-0000000000000001'));
        $this->expectException(PDOException::class);
        self::pdo()->exec('UPDATE orders SET total_minor = total_minor + 1');
    }

    private static function column(string $sql): mixed
    {
        $statement = self::pdo()->query($sql);
        self::assertNotFalse($statement);

        return $statement->fetchColumn();
    }
}
