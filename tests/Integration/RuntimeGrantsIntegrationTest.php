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

        // Spaceship price checks are append-only too (SP-7).
        $app->exec("INSERT INTO spaceship_price_checks (id, trigger_kind, checked_at, outcome, reason, names_asked, names_answered, names_priced)
                    VALUES (REPEAT('c', 32), 'cli', NOW(6), 'done', NULL, 20, 0, 0)");
        self::assertDenied($app, "UPDATE spaceship_price_checks SET names_priced = 1 WHERE id = REPEAT('c', 32)");
        self::assertDenied($app, "DELETE FROM spaceship_price_checks WHERE id = REPEAT('c', 32)");
        self::assertDenied($app, "DELETE FROM spaceship_price_check_items WHERE check_id = REPEAT('c', 32)");

        // Orders and invoices (CD-24, C-44): status may move; totals and lines never change.
        $app->exec("INSERT INTO accounts (id, type, name) VALUES (REPEAT('d', 32), 'personal', 'T')");
        $app->exec("INSERT INTO identities (id, email, email_lookup, full_name, phone_e164, password_hash, status, terms_version, terms_accepted_at, verified_at)
                    VALUES (REPEAT('e', 32), 'g@example.test', REPEAT('9', 64), 'G', '+2348000000000', '\$argon2id\$x', 'active', 'v1', NOW(6), NOW(6))");
        $app->exec("INSERT INTO orders (id, number, account_id, placed_by, status, currency, subtotal_minor, tax_minor, total_minor, tax_country, tax_name, tax_rate_bp,
                        billing_country, visitor_country, flagged_for_review, terms_version, idempotency_key, request_hash, placed_at, expires_at, status_changed_at)
                    VALUES (REPEAT('o', 32), 'PXO-2026-000001', REPEAT('d', 32), REPEAT('e', 32), 'pending_payment', 'NGN', 100, 0, 100, 'NG', 'VAT', 750,
                        'NG', NULL, 0, 'v1', 'key-0000000000000001', REPEAT('h', 64), NOW(6), NOW(6), NOW(6))");
        $app->exec("INSERT INTO order_lines (order_id, line_no, kind, domain, years, price_minor, tax_minor, total_minor, renewal_price_minor, registrar, cost_minor, cost_currency, fx_set_id)
                    VALUES (REPEAT('o', 32), 1, 'domain_register', 'grants-test.com', 1, 100, 0, 100, 100, 'domainnameapi', 50, 'USD', REPEAT('f', 32))");
        $app->exec("UPDATE orders SET status = 'cancelled', status_changed_at = NOW(6) WHERE id = REPEAT('o', 32)");
        self::assertDenied($app, "UPDATE orders SET total_minor = 1, subtotal_minor = 1 WHERE id = REPEAT('o', 32)");
        self::assertDenied($app, "UPDATE orders SET account_id = REPEAT('d', 32) WHERE id = REPEAT('o', 32)");
        self::assertDenied($app, "DELETE FROM orders WHERE id = REPEAT('o', 32)");
        self::assertDenied($app, "UPDATE order_lines SET price_minor = 1 WHERE order_id = REPEAT('o', 32)");
        self::assertDenied($app, "DELETE FROM order_lines WHERE order_id = REPEAT('o', 32)");
        self::assertDenied($app, 'UPDATE invoices SET total_minor = 1');
        self::assertDenied($app, 'DELETE FROM invoice_lines');
        self::assertDenied($app, "UPDATE tax_rates SET rate_bp = 0 WHERE country = 'NG'");
        // The tax editor adds rows (CD-28); history can never be rewritten or removed.
        $app->exec("INSERT INTO tax_rates (country, tax_name, rate_bp, effective_from, created_by) VALUES ('NG', 'VAT', 800, '2099-01-01 00:00:00', 'grants-test')");
        self::assertDenied($app, "DELETE FROM tax_rates WHERE created_by = 'grants-test'");

        // The ledger is append-only (PY-13, LED-002): entries and lines are written once; the chart of accounts is read-only.
        $app->exec("INSERT INTO journal_entries (id, source_key, kind, currency, posted_at) VALUES (REPEAT('a', 32), 'payment:grants-test', 'payment', 'NGN', NOW(6))");
        $app->exec("INSERT INTO journal_lines (entry_id, line_no, account_code, customer_account_id, debit_minor, credit_minor)
                    VALUES (REPEAT('a', 32), 1, 'clearing_paystack', NULL, 100, 0), (REPEAT('a', 32), 2, 'receivable', REPEAT('d', 32), 0, 100)");
        self::assertDenied($app, "UPDATE journal_lines SET debit_minor = 1 WHERE entry_id = REPEAT('a', 32)");
        self::assertDenied($app, "DELETE FROM journal_lines WHERE entry_id = REPEAT('a', 32)");
        self::assertDenied($app, "UPDATE journal_entries SET currency = 'USD' WHERE id = REPEAT('a', 32)");
        self::assertDenied($app, "DELETE FROM journal_entries WHERE id = REPEAT('a', 32)");
        self::assertDenied($app, "INSERT INTO ledger_accounts (code, name, normal_side, per_customer) VALUES ('owner_drawings', 'X', 'debit', 0)");
        self::assertDenied($app, "UPDATE ledger_accounts SET normal_side = 'credit' WHERE code = 'receivable'");

        // Payment attempts (PY-5, PY-9): state and settlement columns move; amount, currency, reference and invoice never change.
        $app->exec("INSERT INTO invoices (id, number, account_id, order_id, status, currency, subtotal_minor, tax_minor, total_minor, tax_country, tax_name, tax_rate_bp,
                        bill_to_name, bill_to_line1, bill_to_city, bill_to_region, bill_to_country, bill_to_phone, issued_at, due_at, expires_at, status_changed_at)
                    VALUES (REPEAT('i', 32), 'PXC-2026-000001', REPEAT('d', 32), REPEAT('o', 32), 'unpaid', 'NGN', 100, 0, 100, 'NG', 'VAT', 750,
                        'G', '1 Test Street', 'Ikeja', 'Lagos', 'NG', '+2348000000000', NOW(6), NOW(6), NOW(6), NOW(6))");
        $app->exec("INSERT INTO payment_attempts (id, reference, account_id, invoice_id, initiated_by, gateway, state, amount_minor, currency, idempotency_key, request_hash, created_at, state_changed_at)
                    VALUES (REPEAT('p', 32), 'PXC-01JBTEST0000000000000000AB', REPEAT('d', 32), REPEAT('i', 32), REPEAT('e', 32), 'paystack', 'initiated', 100, 'NGN',
                        'pay-key-0000000000001', REPEAT('h', 64), NOW(6), NOW(6))");
        $app->exec("UPDATE payment_attempts SET claimed_at = NOW(6) WHERE id = REPEAT('p', 32) AND state = 'initiated'");
        $app->exec("UPDATE payment_attempts SET state = 'pending', checkout_url = 'https://checkout.paystack.com/x', next_check_at = NOW(6), state_changed_at = NOW(6) WHERE id = REPEAT('p', 32)");
        $app->exec("UPDATE payment_attempts SET checks = checks + 1, next_check_at = NOW(6) WHERE id = REPEAT('p', 32) AND checks = 0");
        $app->exec("UPDATE payment_attempts SET state = 'succeeded', gateway_transaction_id = '4099260516', paid_minor = 100, paid_currency = 'NGN', fee_minor = 2,
                        paid_at = NOW(6), next_check_at = NULL, state_changed_at = NOW(6) WHERE id = REPEAT('p', 32) AND state IN ('initiated', 'pending', 'unknown')");
        $app->exec("UPDATE invoices SET status = 'paid', status_changed_at = NOW(6) WHERE id = REPEAT('i', 32) AND status = 'unpaid'");
        self::assertDenied($app, "UPDATE payment_attempts SET amount_minor = 1 WHERE id = REPEAT('p', 32)");
        self::assertDenied($app, "UPDATE payment_attempts SET currency = 'USD' WHERE id = REPEAT('p', 32)");
        self::assertDenied($app, "UPDATE payment_attempts SET invoice_id = REPEAT('j', 32) WHERE id = REPEAT('p', 32)");
        self::assertDenied($app, "UPDATE payment_attempts SET reference = 'PXC-01JBTEST0000000000000000AC' WHERE id = REPEAT('p', 32)");
        self::assertDenied($app, "DELETE FROM payment_attempts WHERE id = REPEAT('p', 32)");

        // Gateway webhook events (PY-7): written once, never edited or removed.
        $app->exec("INSERT INTO gateway_events (id, gateway, event_key, event, reference, payload_sha256, received_at)
                    VALUES (REPEAT('g', 32), 'paystack', REPEAT('a', 64), 'charge.success', 'PXC-01JBTEST0000000000000000AB', REPEAT('b', 64), NOW(6))");
        self::assertDenied($app, "UPDATE gateway_events SET event = 'charge.failed' WHERE id = REPEAT('g', 32)");
        self::assertDenied($app, "DELETE FROM gateway_events WHERE id = REPEAT('g', 32)");

        // Gateway keys (PY-3): saved and re-encrypted, never deleted by the app.
        $app->exec("INSERT INTO payment_gateway_credentials (id, provider, environment, access_level, envelope, key_id, review_due_on) VALUES ('paystack-dev', 'paystack', 'dev', 'read-write', 'x', 'k1', '2027-01-01')");
        $app->exec("UPDATE payment_gateway_credentials SET envelope = 'y' WHERE id = 'paystack-dev'");
        self::assertDenied($app, "DELETE FROM payment_gateway_credentials WHERE id = 'paystack-dev'");

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
            self::assertContains(is_int($code) ? $code : 0, [1142, 1143, 1044], $sql . ' → ' . $e->getMessage());
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
