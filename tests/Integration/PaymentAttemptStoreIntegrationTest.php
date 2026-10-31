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
use PaxofiCloud\Application\Payments\NewPaymentAttempt;
use PaxofiCloud\Bootstrap\AppFactory;
use PaxofiCloud\Domain\Billing\TaxRate;
use PaxofiCloud\Domain\Money\BasisPoints;
use PaxofiCloud\Domain\Money\Currency;
use PaxofiCloud\Domain\Money\Money;
use PaxofiCloud\Domain\Orders\InvoiceStatus;
use PaxofiCloud\Domain\Payments\Gateway;
use PaxofiCloud\Domain\Payments\PaymentReference;
use PaxofiCloud\Infrastructure\Billing\MysqlBillingStore;
use PaxofiCloud\Infrastructure\Container\Resolve;
use PaxofiCloud\Infrastructure\Ledger\MysqlLedger;
use PaxofiCloud\Infrastructure\Migrations\MigrationLoader;
use PaxofiCloud\Infrastructure\Migrations\Migrator;
use PaxofiCloud\Infrastructure\Migrations\MysqlAdvisoryLock;
use PaxofiCloud\Infrastructure\Orders\MysqlOrderStore;
use PaxofiCloud\Infrastructure\Payments\MysqlPaymentAttemptStore;
use PDOException;

/** Migration 0027 and MysqlPaymentAttemptStore on MySQL 8.4 (payments PY-5, PY-6, PY-19). */
final class PaymentAttemptStoreIntegrationTest extends IntegrationTestCase
{
    private const string ALICE = 'd1000000000000000000000000000001';
    private const string ALICE_ID = 'd2000000000000000000000000000001';
    private const string BOB = 'd1000000000000000000000000000002';

    private MysqlPaymentAttemptStore $attempts;
    private string $orderId;
    private string $invoiceId;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        self::resetDatabase();
        $container = AppFactory::create(self::basePath())->container();
        $repository = Resolve::get($container, Repository::class);
        $connection = Resolve::get($container, Connection::class);
        $transactions = Resolve::get($container, TransactionManager::class);
        (new Migrator($connection, $repository, new MysqlAdvisoryLock($repository)))->migrate((new MigrationLoader())->load(self::basePath() . '/migrations'));
        $pdo = self::pdo();
        foreach ([self::ALICE, self::BOB] as $account) {
            $pdo->prepare("INSERT INTO accounts (id, type, name) VALUES (?, 'personal', 'Test')")->execute([$account]);
        }
        $pdo->prepare("INSERT INTO identities (id, email, email_lookup, full_name, phone_e164, password_hash, status, terms_version, terms_accepted_at, verified_at)
            VALUES (?, 'alice@example.test', ?, 'Alice', '+2348000000000', '\$argon2id\$v=19\$m=65536,t=4,p=1\$x\$y', 'active', 'v1', NOW(6), NOW(6))")
            ->execute([self::ALICE_ID, hash('sha256', 'alice@example.test')]);
        $details = BillingDetails::fromInput([
            'full_name' => 'Alice Test', 'address_line1' => '1 Test Street', 'city' => 'Ikeja', 'region' => 'Lagos', 'country' => 'NG', 'phone' => '+2348000000000',
        ]);
        (new MysqlBillingStore($connection, $repository))->save(self::ALICE, $details, Currency::NGN, new \DateTimeImmutable('2026-10-31 09:00:00 UTC'));
        $this->now = new \DateTimeImmutable('2026-10-31 10:00:00 UTC');
        $this->orderId = bin2hex(random_bytes(16));
        (new MysqlOrderStore($connection, $repository, $transactions, new MysqlLedger($connection, $repository, $transactions)))->place(new NewOrder(
            $this->orderId,
            bin2hex(random_bytes(16)),
            self::ALICE,
            self::ALICE_ID,
            Currency::NGN,
            new TaxRate('NG', 'VAT', new BasisPoints(750)),
            $details,
            'NG',
            false,
            '2026-10-01',
            'order-key-000000001',
            hash('sha256', 'order-key-000000001'),
            $this->now,
            $this->now->modify('+72 hours'),
            [new NewOrderLine('paxofi-test.com', 1_650_000, 123_750, 1_815_000, 'domainnameapi', 1000, 'USD', str_repeat('f', 32))],
        ));
        $invoiceId = self::column("SELECT id FROM invoices WHERE order_id = '{$this->orderId}'");
        self::assertIsString($invoiceId);
        $this->invoiceId = $invoiceId;
        $this->attempts = new MysqlPaymentAttemptStore($connection, $repository);
    }

    private function attempt(string $key = 'pay-key-00000000001', ?\DateTimeImmutable $at = null): NewPaymentAttempt
    {
        return new NewPaymentAttempt(
            bin2hex(random_bytes(16)),
            PaymentReference::generate($at ?? $this->now),
            self::ALICE,
            $this->invoiceId,
            self::ALICE_ID,
            Gateway::Paystack,
            Money::of(1_773_750, 'NGN'),
            $key,
            hash('sha256', $key),
            $at ?? $this->now,
        );
    }

    public function testReadsThePayableInvoiceForTheAccountOnly(): void
    {
        $invoice = $this->attempts->payableInvoice(self::ALICE, $this->orderId);

        self::assertNotNull($invoice);
        self::assertSame($this->invoiceId, $invoice->invoiceId);
        self::assertSame(InvoiceStatus::Unpaid, $invoice->status);
        self::assertSame(1_773_750, $invoice->balance->minorUnits);
        self::assertSame('NGN', $invoice->balance->currency->value);
        self::assertSame('2026-11-03T10:00:00+00:00', $invoice->expiresAt->format(\DATE_ATOM));
        self::assertNull($this->attempts->payableInvoice(self::BOB, $this->orderId));
    }

    public function testCreatesFindsAndScopesAnAttempt(): void
    {
        $attempt = $this->attempt();
        $this->attempts->create($attempt);

        $row = $this->attempts->find(self::ALICE, $attempt->id);
        self::assertNotNull($row);
        self::assertSame('initiated', $row['state']);
        self::assertSame($attempt->reference->value, $row['reference']);
        self::assertSame(1_773_750, $row['amount']);
        self::assertSame($this->orderId, $row['order_id']);
        self::assertNull($row['checkout_url']);
        self::assertNull($this->attempts->find(self::BOB, $attempt->id));
        self::assertSame(['id' => $attempt->id, 'request_hash' => $attempt->requestHash], $this->attempts->findByIdempotencyKey(self::ALICE, 'pay-key-00000000001'));
        self::assertNull($this->attempts->findByIdempotencyKey(self::BOB, 'pay-key-00000000001'));
        self::assertSame(1, $this->attempts->openAttempts($this->invoiceId));
    }

    public function testAReusedKeyIsReportedAsADuplicate(): void
    {
        $this->attempts->create($this->attempt());

        $this->expectException(DuplicateIdempotencyKey::class);
        $this->attempts->create($this->attempt());
    }

    public function testAClaimIsTakenOnceAndCarriesThePayerEmail(): void
    {
        $attempt = $this->attempt();
        $this->attempts->create($attempt);

        $claimed = $this->attempts->claimNext($this->now);
        self::assertNotNull($claimed);
        self::assertSame($attempt->id, $claimed->id);
        self::assertSame('alice@example.test', $claimed->payerEmail);
        self::assertSame('PXC-2026-000001', $claimed->invoiceNumber);
        self::assertFalse($claimed->reclaimed);
        self::assertNull($this->attempts->claimNext($this->now), 'a fresh claim is not handed out again');

        $later = $this->attempts->claimNext($this->now->modify('+301 seconds'));
        self::assertNotNull($later);
        self::assertTrue($later->reclaimed);
    }

    public function testStatesMoveOnlyFromInitiated(): void
    {
        $ready = $this->attempt();
        $failed = $this->attempt('pay-key-00000000002');
        $this->attempts->create($ready);
        $this->attempts->create($failed);

        self::assertTrue($this->attempts->markCheckoutReady($ready->id, 'https://checkout.paystack.com/abc', $this->now));
        self::assertFalse($this->attempts->markFailed($ready->id, 'transport', $this->now), 'a pending attempt is not failed by the start step');
        self::assertTrue($this->attempts->markFailed($failed->id, 'key_mode_mismatch', $this->now));
        self::assertFalse($this->attempts->markCheckoutReady($failed->id, 'https://checkout.paystack.com/late', $this->now));

        self::assertSame('https://checkout.paystack.com/abc', $this->attempts->find(self::ALICE, $ready->id)['checkout_url'] ?? null);
        $row = $this->attempts->find(self::ALICE, $failed->id);
        self::assertSame('failed', $row['state'] ?? null);
        self::assertSame('key_mode_mismatch', $row['failure_reason'] ?? null);
        self::assertNull($row['checkout_url'] ?? null);
        self::assertSame(1, $this->attempts->openAttempts($this->invoiceId));
        self::assertNull($this->attempts->claimNext($this->now->modify('+1 hour')));
    }

    public function testConnectedGatewaysFollowTheEnvironment(): void
    {
        $pdo = self::pdo();
        $insert = $pdo->prepare("INSERT INTO payment_gateway_credentials (id, provider, environment, access_level, envelope, key_id, review_due_on) VALUES (?, ?, ?, 'read-write', 'x', 'k1', '2027-10-31')");
        $insert->execute(['flutterwave-staging', 'flutterwave', 'staging']);
        $insert->execute(['paystack-prod', 'paystack', 'prod']);

        self::assertSame([Gateway::Flutterwave], $this->attempts->connectedGateways('staging'));
        self::assertSame([Gateway::Paystack], $this->attempts->connectedGateways('prod'));
        self::assertSame([], $this->attempts->connectedGateways('dev'));
    }

    public function testChecksRefuseBadRows(): void
    {
        $this->attempts->create($this->attempt());
        $this->expectException(PDOException::class);
        self::pdo()->exec("UPDATE payment_attempts SET checkout_url = 'http://checkout.paystack.com/x'");
    }

    private static function column(string $sql): mixed
    {
        $statement = self::pdo()->query($sql);
        self::assertNotFalse($statement);

        return $statement->fetchColumn();
    }
}
