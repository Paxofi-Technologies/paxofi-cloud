<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Application\Payments;

use PaxofiCloud\Application\Audit\AuditEventType;
use PaxofiCloud\Application\Audit\ClientContext;
use PaxofiCloud\Application\Payments\InvoicePayments;
use PaxofiCloud\Application\Payments\PayableInvoice;
use PaxofiCloud\Application\Payments\PaymentRefused;
use PaxofiCloud\Domain\Money\Money;
use PaxofiCloud\Domain\Orders\InvoiceStatus;
use PaxofiCloud\Domain\Payments\Gateway;
use PaxofiCloud\Domain\Payments\PaymentReference;
use PaxofiCloud\Tests\Support\AuditFixtures;
use PaxofiCloud\Tests\Support\InMemoryAuditLog;
use PaxofiCloud\Tests\Support\InMemoryBillingStore;
use PaxofiCloud\Tests\Support\InMemoryPaymentAttemptStore;
use PaxofiCloud\Tests\Support\MutableClock;
use PaxofiCloud\Tests\Support\ScriptedRateLimiter;
use PHPUnit\Framework\TestCase;

/**
 * THREAT-MODEL-PAYMENTS.md PY-5, PY-6, PY-17; PT-03, PT-04, PT-20; SRS
 * PAY-002, PAY-004. Test accounts and amounts only.
 */
final class InvoicePaymentsTest extends TestCase
{
    private const string ALICE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const string ALICE_ID = '11111111111111111111111111111111';
    private const string BOB = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const string BOB_ID = '22222222222222222222222222222222';
    private const string ORDER = 'cccccccccccccccccccccccccccccccc';
    private const string INVOICE = 'dddddddddddddddddddddddddddddddd';
    private const string KEY = 'pay-key-00000000001';

    private InMemoryPaymentAttemptStore $attempts;
    private InMemoryBillingStore $billing;
    private InMemoryAuditLog $audit;
    private ScriptedRateLimiter $limiter;
    private MutableClock $clock;

    protected function setUp(): void
    {
        $this->attempts = new InMemoryPaymentAttemptStore();
        $this->attempts->connected['staging'] = [Gateway::Flutterwave, Gateway::Paystack];
        $this->billing = new InMemoryBillingStore();
        $this->billing->owners = [self::ALICE => self::ALICE_ID, self::BOB => self::BOB_ID];
        $this->audit = new InMemoryAuditLog();
        $this->limiter = new ScriptedRateLimiter();
        $this->clock = new MutableClock(new \DateTimeImmutable('2026-10-31T10:00:00Z'));
        $this->invoice(InvoiceStatus::Unpaid, Money::of(1_234_500, 'NGN'));
    }

    private function invoice(InvoiceStatus $status, Money $total, string $expires = '2026-11-03T10:00:00Z'): void
    {
        $this->attempts->addInvoice(self::ALICE, self::ORDER, new PayableInvoice(self::INVOICE, 'PXC-2026-000042', $status, $total, new \DateTimeImmutable($expires)));
    }

    private function payments(): InvoicePayments
    {
        return new InvoicePayments($this->attempts, $this->billing, $this->limiter, AuditFixtures::trail($this->audit), $this->clock, 'staging');
    }

    /** @return array{payment_id: string, replayed: bool} */
    private function start(string $gateway = 'paystack', string $key = self::KEY, string $account = self::ALICE, string $identity = self::ALICE_ID, string $order = self::ORDER): array
    {
        return $this->payments()->start($identity, $account, $order, $gateway, $key, ClientContext::ip('192.0.2.10'));
    }

    private function refusal(\Closure $call): string
    {
        try {
            $call();
        } catch (PaymentRefused $e) {
            return $e->reason;
        }
        self::fail('Expected the payment to be refused.');
    }

    public function testStartRecordsAnInitiatedAttemptForTheInvoiceBalance(): void
    {
        $started = $this->start();

        self::assertFalse($started['replayed']);
        $row = $this->attempts->attempts[$started['payment_id']];
        self::assertSame('initiated', $row['state']);
        self::assertSame(1_234_500, $row['attempt']->amount->minorUnits, 'PY-5: amount copied from the invoice, not the request');
        self::assertSame('NGN', $row['attempt']->amount->currency->value);
        self::assertSame(Gateway::Paystack, $row['attempt']->gateway);
        self::assertSame(self::INVOICE, $row['attempt']->invoiceId);
        self::assertSame(self::ALICE_ID, $row['attempt']->initiatedBy);
        self::assertNotNull(PaymentReference::tryFrom($row['attempt']->reference->value));
        self::assertSame([AuditEventType::PAYMENT_STARTED], $this->audit->types());
        self::assertSame('paystack', $this->audit->events[0]->details['gateway']);
        self::assertSame(1_234_500, $this->audit->events[0]->details['amount_minor']);
    }

    public function testTheSameKeyForTheSameRequestAnswersWithTheFirstAttempt(): void
    {
        $first = $this->start();
        $second = $this->start();

        self::assertTrue($second['replayed']);
        self::assertSame($first['payment_id'], $second['payment_id']);
        self::assertCount(1, $this->attempts->attempts);
    }

    public function testTheSameKeyForAnotherGatewayIsRefused(): void
    {
        $this->start('paystack');

        self::assertSame(PaymentRefused::KEY_REUSED, $this->refusal(fn () => $this->start('flutterwave')));
    }

    public function testAConcurrentRequestWithTheSameKeyGetsTheWinnersAttempt(): void
    {
        $this->attempts->raceOnCreate = true;

        $started = $this->start();

        self::assertTrue($started['replayed']);
        self::assertSame(str_repeat('e', 32), $started['payment_id']);
        self::assertSame([], $this->audit->types());
    }

    public function testAnotherAccountsOrderIsNotFound(): void
    {
        self::assertSame(PaymentRefused::INVOICE_NOT_FOUND, $this->refusal(fn () => $this->start(account: self::BOB, identity: self::BOB_ID)));
        self::assertSame(PaymentRefused::INVOICE_NOT_FOUND, $this->refusal(fn () => $this->payments()->options(self::BOB, self::ORDER)));
        self::assertSame([], $this->attempts->attempts);
    }

    public function testOnlyTheAccountOwnerMayPay(): void
    {
        self::assertSame(PaymentRefused::NOT_OWNER, $this->refusal(fn () => $this->start(identity: self::BOB_ID)));
    }

    public function testAPaidOrCancelledInvoiceIsNotPayable(): void
    {
        $this->invoice(InvoiceStatus::Paid, Money::of(1_234_500, 'NGN'));
        self::assertSame(PaymentRefused::NOT_PAYABLE, $this->refusal(fn () => $this->start()));

        $this->invoice(InvoiceStatus::Cancelled, Money::of(1_234_500, 'NGN'));
        self::assertSame(PaymentRefused::NOT_PAYABLE, $this->refusal(fn () => $this->start(key: 'pay-key-00000000002')));
    }

    public function testAnExpiredInvoiceIsNotPayable(): void
    {
        $this->invoice(InvoiceStatus::Unpaid, Money::of(1_234_500, 'NGN'), '2026-10-31T10:00:00Z');

        self::assertSame(PaymentRefused::EXPIRED, $this->refusal(fn () => $this->start()));
        self::assertFalse($this->payments()->options(self::ALICE, self::ORDER)['payable']);
    }

    public function testOnlyConnectedGatewaysAreOffered(): void
    {
        $this->attempts->connected['staging'] = [Gateway::Flutterwave];

        self::assertSame(['flutterwave'], $this->payments()->options(self::ALICE, self::ORDER)['gateways']);
        self::assertSame(PaymentRefused::GATEWAY_UNAVAILABLE, $this->refusal(fn () => $this->start('paystack')));
        self::assertSame(PaymentRefused::GATEWAY_UNAVAILABLE, $this->refusal(fn () => $this->start('stripe', 'pay-key-00000000003')));
    }

    public function testAnotherEnvironmentsCredentialDoesNotCount(): void
    {
        $this->attempts->connected = ['prod' => [Gateway::Paystack]];

        self::assertSame([], $this->payments()->options(self::ALICE, self::ORDER)['gateways']);
        self::assertSame(PaymentRefused::GATEWAY_UNAVAILABLE, $this->refusal(fn () => $this->start()));
    }

    public function testUsdInvoicesHaveNoGatewayYet(): void
    {
        $this->invoice(InvoiceStatus::Unpaid, Money::of(5_000, 'USD'));

        $options = $this->payments()->options(self::ALICE, self::ORDER);

        self::assertTrue($options['payable']);
        self::assertSame([], $options['gateways'], 'PY-17: Paystack USD waits on PA-5, Flutterwave USD on R-2');
        self::assertSame(PaymentRefused::GATEWAY_UNAVAILABLE, $this->refusal(fn () => $this->start()));
    }

    public function testAnAmountBelowPaystacksMinimumIsRefusedUpFront(): void
    {
        $this->invoice(InvoiceStatus::Unpaid, Money::of(4_999, 'NGN'));

        self::assertSame(PaymentRefused::BELOW_MINIMUM, $this->refusal(fn () => $this->start('paystack')));
        self::assertFalse($this->start('flutterwave', 'pay-key-00000000004')['replayed']);
    }

    public function testOpenAttemptsPerInvoiceAreCapped(): void
    {
        for ($i = 0; $i < InvoicePayments::MAX_OPEN_ATTEMPTS; $i++) {
            $this->start(key: sprintf('pay-key-%011d', $i + 10));
        }

        self::assertSame(PaymentRefused::TOO_MANY_OPEN, $this->refusal(fn () => $this->start(key: 'pay-key-00000000099')));
    }

    public function testStartsAreRateLimitedPerAccount(): void
    {
        $this->limiter->hits['payments:start:' . hash('sha256', self::ALICE)] = InvoicePayments::ATTEMPTS_PER_HOUR;

        self::assertSame(PaymentRefused::TOO_MANY, $this->refusal(fn () => $this->start()));
    }

    public function testAMalformedIdempotencyKeyIsRefused(): void
    {
        self::assertSame(PaymentRefused::KEY_INVALID, $this->refusal(fn () => $this->start(key: 'short')));
        self::assertSame(PaymentRefused::KEY_INVALID, $this->refusal(fn () => $this->start(key: str_repeat('k', 15) . ' ')));
    }

    public function testOptionsDescribeTheInvoice(): void
    {
        self::assertSame([
            'invoice_number' => 'PXC-2026-000042',
            'status' => 'unpaid',
            'amount' => 1_234_500,
            'currency' => 'NGN',
            'payable' => true,
            'gateways' => ['flutterwave', 'paystack'],
        ], $this->payments()->options(self::ALICE, self::ORDER));
    }

    public function testStatusIsScopedToTheAccount(): void
    {
        $id = $this->start()['payment_id'];

        self::assertSame('initiated', $this->payments()->status(self::ALICE, $id)['state']);
        self::assertNull($this->payments()->status(self::ALICE, $id)['checkout_url']);
        self::assertSame(PaymentRefused::ATTEMPT_NOT_FOUND, $this->refusal(fn () => $this->payments()->status(self::BOB, $id)));
    }
    public function testComingBackFromTheGatewayAsksForACheckNowAtMostEvery30Seconds(): void
    {
        $id = $this->start()['payment_id'];
        $this->attempts->markCheckoutReady($id, 'https://checkout.paystack.com/x', $this->clock->now(), $this->clock->now()->modify('+2 minutes'));

        self::assertSame('pending', $this->payments()->checkNow(self::ALICE, $id)['state']);
        self::assertEquals($this->clock->now(), $this->attempts->attempts[$id]['next'], 'PY-10: due now');

        $this->attempts->attempts[$id]['next'] = $this->clock->now()->modify('+10 minutes');
        $this->payments()->checkNow(self::ALICE, $id);
        self::assertEquals($this->clock->now()->modify('+10 minutes'), $this->attempts->attempts[$id]['next'], 'a second ask within 30 s changes nothing');
    }

    public function testAnotherAccountCannotAskForACheck(): void
    {
        $id = $this->start()['payment_id'];

        self::assertSame(PaymentRefused::ATTEMPT_NOT_FOUND, $this->refusal(fn () => $this->payments()->checkNow(self::BOB, $id)));
    }

    public function testASettledAttemptIsNotCheckedAgain(): void
    {
        $id = $this->start()['payment_id'];
        $this->attempts->markFailed($id, 'transport', $this->clock->now());

        self::assertSame('failed', $this->payments()->checkNow(self::ALICE, $id)['state']);
        self::assertSame([], array_filter(array_keys($this->limiter->hits), static fn (string $k): bool => str_starts_with($k, 'payments:check:')));
    }
}
