<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Integration;

use Paxofi\Core\Contracts\Connection;
use Paxofi\Core\Contracts\Repository;
use Paxofi\Core\Contracts\TransactionManager;
use PaxofiCloud\Bootstrap\AppFactory;
use PaxofiCloud\Domain\Ledger\JournalEntry;
use PaxofiCloud\Domain\Ledger\LedgerAccount;
use PaxofiCloud\Domain\Ledger\Postings;
use PaxofiCloud\Domain\Money\Currency;
use PaxofiCloud\Infrastructure\Container\Resolve;
use PaxofiCloud\Infrastructure\Ledger\MysqlLedger;
use PaxofiCloud\Infrastructure\Migrations\Migration;
use PaxofiCloud\Infrastructure\Migrations\MigrationLoader;
use PaxofiCloud\Infrastructure\Migrations\Migrator;
use PaxofiCloud\Infrastructure\Migrations\MysqlAdvisoryLock;
use PDOException;

/** Migration 0025 and MysqlLedger on MySQL 8.4 (payments threat model PY-13, PY-14, PT-06, PT-12, PT-22). */
final class LedgerIntegrationTest extends IntegrationTestCase
{
    private const string ALICE = 'c1000000000000000000000000000001';
    private const string IDENTITY = 'c2000000000000000000000000000001';

    private MysqlLedger $ledger;
    private Migrator $migrator;
    /** @var list<Migration> */
    private array $migrations;

    protected function setUp(): void
    {
        parent::setUp();
        self::resetDatabase();
        $container = AppFactory::create(self::basePath())->container();
        $repository = Resolve::get($container, Repository::class);
        $connection = Resolve::get($container, Connection::class);
        $this->migrator = new Migrator($connection, $repository, new MysqlAdvisoryLock($repository));
        $this->migrations = (new MigrationLoader())->load(self::basePath() . '/migrations');
        $this->ledger = new MysqlLedger($connection, $repository, Resolve::get($container, TransactionManager::class));
    }

    public function testBackfillGivesEveryEarlierInvoiceItsIssueEntry(): void
    {
        // Migrate up to the orders table, place two invoices "before the ledger existed", then add the ledger.
        $this->migrator->migrate(array_values(array_filter($this->migrations, static fn (Migration $m): bool => (int) $m->version < 25)));
        $this->seedAccount();
        $this->seedInvoice('a', 100_000, 7_500);
        $this->seedInvoice('b', 50_000, 0);
        $this->migrator->migrate($this->migrations);

        self::assertSame(157_500, $this->ledger->balance(LedgerAccount::Receivable, Currency::NGN, self::ALICE));
        self::assertSame(-150_000, $this->ledger->balance(LedgerAccount::RevenueDomains, Currency::NGN));
        self::assertSame(-7_500, $this->ledger->balance(LedgerAccount::TaxPayable, Currency::NGN));
        self::assertTrue($this->ledger->verify()->ok());

        // The application posting for a backfilled invoice is a no-op (same source key).
        self::assertFalse($this->ledger->post(Postings::invoiceIssued(str_repeat('9', 32), str_repeat('a', 32), self::ALICE, Currency::NGN, 100_000, 7_500, new \DateTimeImmutable())));
        self::assertSame(157_500, $this->ledger->balance(LedgerAccount::Receivable, Currency::NGN, self::ALICE));
    }

    public function testPostingIsIdempotentAndReversalUndoesAnEntryOnce(): void
    {
        $this->migrator->migrate($this->migrations);
        $this->seedAccount();
        $entry = Postings::invoiceIssued(str_repeat('1', 32), str_repeat('a', 32), self::ALICE, Currency::USD, 1_000, 0, new \DateTimeImmutable('2026-10-26 10:00:00 UTC'));

        self::assertTrue($this->ledger->post($entry));
        self::assertFalse($this->ledger->post(Postings::invoiceIssued(str_repeat('2', 32), str_repeat('a', 32), self::ALICE, Currency::USD, 1_000, 0, new \DateTimeImmutable())), 'same source key');
        self::assertSame(1_000, $this->ledger->balance(LedgerAccount::Receivable, Currency::USD, self::ALICE));
        self::assertSame(0, $this->ledger->balance(LedgerAccount::Receivable, Currency::NGN, self::ALICE), 'balances are per currency');

        self::assertTrue($this->ledger->post($entry->reversal(str_repeat('3', 32), new \DateTimeImmutable())));
        self::assertFalse($this->ledger->post($entry->reversal(str_repeat('4', 32), new \DateTimeImmutable())), 'an entry is reversed at most once');
        self::assertSame(0, $this->ledger->balance(LedgerAccount::Receivable, Currency::USD, self::ALICE));
        self::assertSame(0, $this->ledger->balance(LedgerAccount::RevenueDomains, Currency::USD));
    }

    public function testVerifyFindsAnUnbalancedEntryAndAnInvoiceWithoutItsEntry(): void
    {
        $this->migrator->migrate($this->migrations);
        $this->seedAccount();
        self::assertTrue($this->ledger->verify()->ok());

        // Written behind the application's back (the runtime user could not do this; the owner could).
        $pdo = self::pdo();
        $pdo->exec("INSERT INTO journal_entries (id, source_key, kind, currency, posted_at) VALUES (REPEAT('5', 32), 'payment:tamper', 'payment', 'NGN', NOW(6))");
        $pdo->prepare("INSERT INTO journal_lines (entry_id, line_no, account_code, customer_account_id, debit_minor, credit_minor)
                    VALUES (REPEAT('5', 32), 1, 'clearing_paystack', NULL, 100, 0), (REPEAT('5', 32), 2, 'receivable', ?, 0, 99)")->execute([self::ALICE]);
        $this->seedInvoice('c', 10_000, 0);

        $result = $this->ledger->verify();
        self::assertFalse($result->ok());
        self::assertContains('entry ' . str_repeat('5', 32) . ': debits do not equal credits', $result->problems);
        self::assertContains('invoice ' . str_repeat('c', 32) . ': no matching issue entry', $result->problems);
    }

    public function testTheSchemaRefusesTwoSidedLinesAndMisusedSubLedgers(): void
    {
        $this->migrator->migrate($this->migrations);
        $this->seedAccount();
        $pdo = self::pdo();
        $pdo->exec("INSERT INTO journal_entries (id, source_key, kind, currency, posted_at) VALUES (REPEAT('6', 32), 'payment:checks', 'payment', 'NGN', NOW(6))");

        $insert = $pdo->prepare("INSERT INTO journal_lines (entry_id, line_no, account_code, customer_account_id, debit_minor, credit_minor) VALUES (REPEAT('6', 32), 1, ?, ?, ?, ?)");
        foreach ([
            'both sides' => ['clearing_paystack', null, 100, 100],
            'zero' => ['clearing_paystack', null, 0, 0],
            'receivable without customer' => ['receivable', null, 100, 0],
            'revenue with customer' => ['revenue_domains', self::ALICE, 0, 100],
            'unknown account' => ['owner_drawings', null, 100, 0],
        ] as $case => $values) {
            try {
                $insert->execute($values);
                self::fail('refused: ' . $case);
            } catch (PDOException) {
            }
        }
        $this->expectException(PDOException::class);
        $pdo->exec("INSERT INTO journal_entries (id, source_key, kind, currency, posted_at) VALUES (REPEAT('7', 32), 'refund:wrong-prefix-kind', 'payment', 'NGN', NOW(6))");
    }

    private function seedAccount(): void
    {
        $pdo = self::pdo();
        $pdo->prepare("INSERT INTO accounts (id, type, name) VALUES (?, 'personal', 'Test')")->execute([self::ALICE]);
        $pdo->prepare("INSERT INTO identities (id, email, email_lookup, full_name, phone_e164, password_hash, status, terms_version, terms_accepted_at, verified_at)
            VALUES (?, 'alice@example.test', ?, 'Alice', '+2348000000000', '\$argon2id\$v=19\$m=65536,t=4,p=1\$x\$y', 'active', 'v1', NOW(6), NOW(6))")
            ->execute([self::IDENTITY, hash('sha256', 'alice@example.test')]);
    }

    private function seedInvoice(string $letter, int $subtotal, int $tax): void
    {
        $id = str_repeat($letter, 32);
        $orderNumber = 'PXO-2026-' . str_pad((string) ord($letter), 6, '0', STR_PAD_LEFT);
        $invoiceNumber = 'PXC-2026-' . str_pad((string) ord($letter), 6, '0', STR_PAD_LEFT);
        $key = 'key-' . $letter;
        $pdo = self::pdo();
        $pdo->prepare("INSERT INTO orders (id, number, account_id, placed_by, status, currency, subtotal_minor, tax_minor, total_minor, tax_country, tax_name, tax_rate_bp,
                billing_country, visitor_country, flagged_for_review, terms_version, idempotency_key, request_hash, placed_at, expires_at, status_changed_at)
            VALUES (?, ?, ?, ?, 'pending_payment', 'NGN', ?, ?, ?, 'NG', 'VAT', 750, 'NG', NULL, 0, 'v1', ?, REPEAT('h', 64), NOW(6), NOW(6), NOW(6))")
            ->execute([$id, $orderNumber, self::ALICE, self::IDENTITY, $subtotal, $tax, $subtotal + $tax, $key]);
        $pdo->prepare("INSERT INTO invoices (id, number, account_id, order_id, status, currency, subtotal_minor, tax_minor, total_minor, tax_country, tax_name, tax_rate_bp,
                bill_to_name, bill_to_line1, bill_to_city, bill_to_region, bill_to_country, bill_to_phone, issued_at, due_at, expires_at, status_changed_at)
            VALUES (?, ?, ?, ?, 'unpaid', 'NGN', ?, ?, ?, 'NG', 'VAT', 750, 'Alice', '1 Street', 'Ikeja', 'Lagos', 'NG', '+2348000000000', NOW(6), NOW(6), NOW(6), NOW(6))")
            ->execute([$id, $invoiceNumber, self::ALICE, $id, $subtotal, $tax, $subtotal + $tax]);
    }
}
