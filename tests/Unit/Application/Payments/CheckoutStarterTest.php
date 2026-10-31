<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Application\Payments;

use PaxofiCloud\Application\Audit\AuditEventType;
use PaxofiCloud\Application\Payments\CheckoutStarter;
use PaxofiCloud\Application\Payments\GatewayUnavailable;
use PaxofiCloud\Application\Payments\HostedCheckout;
use PaxofiCloud\Application\Payments\NewPaymentAttempt;
use PaxofiCloud\Application\Payments\PayableInvoice;
use PaxofiCloud\Domain\Money\Money;
use PaxofiCloud\Domain\Orders\InvoiceStatus;
use PaxofiCloud\Domain\Payments\Gateway;
use PaxofiCloud\Domain\Payments\PaymentReference;
use PaxofiCloud\Tests\Support\AuditFixtures;
use PaxofiCloud\Tests\Support\InMemoryAuditLog;
use PaxofiCloud\Tests\Support\InMemoryPaymentAttemptStore;
use PaxofiCloud\Tests\Support\MutableClock;
use PaxofiCloud\Tests\Support\RecordingLogger;
use PaxofiCloud\Tests\Support\ScriptedPaymentGateways;
use PHPUnit\Framework\TestCase;

/** THREAT-MODEL-PAYMENTS.md PY-6, PY-19; PT-11, PT-20, PT-21. */
final class CheckoutStarterTest extends TestCase
{
    private const string ACCOUNT = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const string IDENTITY = '11111111111111111111111111111111';
    private const string INVOICE = 'dddddddddddddddddddddddddddddddd';
    private const string ATTEMPT = 'ffffffffffffffffffffffffffffffff';

    private InMemoryPaymentAttemptStore $attempts;
    private ScriptedPaymentGateways $gateways;
    private InMemoryAuditLog $audit;
    private RecordingLogger $logger;
    private MutableClock $clock;
    private PaymentReference $reference;

    protected function setUp(): void
    {
        $this->attempts = new InMemoryPaymentAttemptStore();
        $this->attempts->addInvoice(self::ACCOUNT, str_repeat('c', 32), new PayableInvoice(self::INVOICE, 'PXC-2026-000042', InvoiceStatus::Unpaid, Money::of(1_234_500, 'NGN'), new \DateTimeImmutable('2026-11-03T10:00:00Z')));
        $this->attempts->emails[self::IDENTITY] = 'owner@example.invalid';
        $this->gateways = new ScriptedPaymentGateways();
        $this->audit = new InMemoryAuditLog();
        $this->logger = new RecordingLogger();
        $this->clock = new MutableClock(new \DateTimeImmutable('2026-10-31T10:00:00Z'));
        $this->reference = PaymentReference::generate($this->clock->now());
        $this->attempts->create(new NewPaymentAttempt(
            self::ATTEMPT, $this->reference, self::ACCOUNT, self::INVOICE, self::IDENTITY, Gateway::Paystack,
            Money::of(1_234_500, 'NGN'), 'pay-key-00000000001', str_repeat('0', 64), $this->clock->now(),
        ));
    }

    private function starter(string $appUrl = 'https://cloud-staging.paxofi.com/'): CheckoutStarter
    {
        return new CheckoutStarter($this->attempts, $this->gateways, $appUrl, AuditFixtures::trail($this->audit), $this->clock, $this->logger);
    }

    public function testAnInitiatedAttemptGetsItsCheckoutPage(): void
    {
        self::assertSame(1, $this->starter()->runOnce());

        $sent = $this->gateways->requests[0];
        self::assertSame(Gateway::Paystack, $sent['gateway']);
        self::assertSame($this->reference->value, $sent['request']->reference->value);
        self::assertSame(1_234_500, $sent['request']->amount->minorUnits);
        self::assertSame('owner@example.invalid', $sent['request']->email);
        self::assertSame('PXC-2026-000042', $sent['request']->invoiceNumber);
        // PT-20: the return address is fixed by the server from APP_URL.
        self::assertSame('https://cloud-staging.paxofi.com/billing/payments/' . self::ATTEMPT, $sent['request']->callbackUrl);
        self::assertSame('pending', $this->attempts->attempts[self::ATTEMPT]['state']);
        self::assertSame('https://checkout.paystack.com/test-page', $this->attempts->find(self::ACCOUNT, self::ATTEMPT)['checkout_url'] ?? null);
        self::assertSame([AuditEventType::PAYMENT_CHECKOUT_READY], $this->audit->types());
        // PY-11: the first gateway check is two minutes after the attempt was created.
        self::assertEquals(new \DateTimeImmutable('2026-10-31T10:02:00Z'), $this->attempts->attempts[self::ATTEMPT]['next']);
    }

    public function testEachAttemptIsStartedOnce(): void
    {
        $this->starter()->runOnce();

        self::assertSame(0, $this->starter()->runOnce());
        self::assertCount(1, $this->gateways->requests);
    }

    public function testAGatewayRefusalFailsTheAttemptWithItsCode(): void
    {
        $this->gateways->answer = static fn (): \Throwable => new GatewayUnavailable('key_mode_mismatch');

        $this->starter()->runOnce();

        self::assertSame('failed', $this->attempts->attempts[self::ATTEMPT]['state']);
        self::assertSame('key_mode_mismatch', $this->attempts->attempts[self::ATTEMPT]['reason']);
        self::assertSame([AuditEventType::PAYMENT_FAILED], $this->audit->types());
        self::assertSame('key_mode_mismatch', $this->audit->events[0]->details['reason']);
    }

    public function testAnUnexpectedErrorIsLoggedWithoutItsMessage(): void
    {
        $this->gateways->answer = static fn (): \Throwable => new \RuntimeException('gateway said: card 4111 declined for owner@example.invalid');

        $this->starter()->runOnce();

        self::assertSame('internal_error', $this->attempts->attempts[self::ATTEMPT]['reason']);
        $logged = json_encode($this->logger->records, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('4111', $logged, 'PY-19: gateway text never reaches the logs');
        self::assertStringNotContainsString('owner@example.invalid', $logged);
    }

    public function testAnAnswerForAnotherReferenceIsRefused(): void
    {
        $this->gateways->answer = fn (): HostedCheckout => new HostedCheckout(PaymentReference::generate($this->clock->now()), 'https://checkout.paystack.com/other');

        $this->starter()->runOnce();

        self::assertSame('malformed', $this->attempts->attempts[self::ATTEMPT]['reason']);
        self::assertNull($this->attempts->find(self::ACCOUNT, self::ATTEMPT)['checkout_url'] ?? null);
    }

    public function testAnAttemptNobodyStartedInTimeFailsWithoutCallingTheGateway(): void
    {
        $this->clock->advance('+' . (CheckoutStarter::STALE_AFTER_SECONDS + 1) . ' seconds');

        $this->starter()->runOnce();

        self::assertSame('stale', $this->attempts->attempts[self::ATTEMPT]['reason']);
        self::assertSame([], $this->gateways->requests);
    }

    public function testAClaimThatNeverFinishedIsNotSentTwice(): void
    {
        // A worker claims the attempt, then stops before marking it.
        self::assertNotNull($this->attempts->claimNext($this->clock->now()));
        $this->clock->advance('+' . (InMemoryPaymentAttemptStore::CLAIM_TIMEOUT_SECONDS + 1) . ' seconds');

        $this->starter()->runOnce();

        self::assertSame('interrupted', $this->attempts->attempts[self::ATTEMPT]['reason']);
        self::assertSame([], $this->gateways->requests);
    }

    public function testARecentClaimIsLeftToItsWorker(): void
    {
        self::assertNotNull($this->attempts->claimNext($this->clock->now()));

        self::assertSame(0, $this->starter()->runOnce());
        self::assertSame('initiated', $this->attempts->attempts[self::ATTEMPT]['state']);
    }

    public function testAPlainHttpAppUrlNeverReachesAGateway(): void
    {
        $this->starter('http://127.0.0.1:8080')->runOnce();

        self::assertSame('request_invalid', $this->attempts->attempts[self::ATTEMPT]['reason']);
        self::assertSame([], $this->gateways->requests);
    }
}
