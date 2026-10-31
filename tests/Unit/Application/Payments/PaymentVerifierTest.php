<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Application\Payments;

use PaxofiCloud\Application\Audit\AuditEventType;
use PaxofiCloud\Application\Payments\GatewayUnavailable;
use PaxofiCloud\Application\Payments\GatewayVerification;
use PaxofiCloud\Application\Payments\NewPaymentAttempt;
use PaxofiCloud\Application\Payments\PayableInvoice;
use PaxofiCloud\Application\Payments\PaymentOutcome;
use PaxofiCloud\Application\Payments\PaymentVerifier;
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

/**
 * THREAT-MODEL-PAYMENTS.md PY-8, PY-9, PY-11, PY-12; PT-03, PT-04, PT-06,
 * PT-07, PT-21; SRS PAY-006, PAY-007. Test amounts only.
 */
final class PaymentVerifierTest extends TestCase
{
    private const string ACCOUNT = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const string IDENTITY = '11111111111111111111111111111111';
    private const string ORDER = 'cccccccccccccccccccccccccccccccc';
    private const string INVOICE = 'dddddddddddddddddddddddddddddddd';
    private const string ATTEMPT = 'ffffffffffffffffffffffffffffffff';
    private const string SECOND = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';

    private InMemoryPaymentAttemptStore $attempts;
    private ScriptedPaymentGateways $gateways;
    private InMemoryAuditLog $audit;
    private RecordingLogger $logger;
    private MutableClock $clock;
    private \DateTimeImmutable $created;

    protected function setUp(): void
    {
        $this->attempts = new InMemoryPaymentAttemptStore();
        $this->attempts->addInvoice(self::ACCOUNT, self::ORDER, new PayableInvoice(self::INVOICE, 'PXC-2026-000042', InvoiceStatus::Unpaid, Money::of(1_234_500, 'NGN'), new \DateTimeImmutable('2026-11-03T10:00:00Z')));
        $this->gateways = new ScriptedPaymentGateways();
        $this->audit = new InMemoryAuditLog();
        $this->logger = new RecordingLogger();
        $this->created = new \DateTimeImmutable('2026-10-31T10:00:00Z');
        $this->clock = new MutableClock($this->created);
        $this->pending(self::ATTEMPT);
        $this->clock->advance('+2 minutes');
    }

    private function pending(string $id, Gateway $gateway = Gateway::Paystack): void
    {
        $this->attempts->create(new NewPaymentAttempt(
            $id, PaymentReference::generate($this->created), self::ACCOUNT, self::INVOICE, self::IDENTITY, $gateway,
            Money::of(1_234_500, 'NGN'), 'pay-key-' . substr($id, 0, 12), str_repeat('0', 64), $this->created,
        ));
        $this->attempts->markCheckoutReady($id, 'https://checkout.paystack.com/x', $this->created, $this->created->modify('+120 seconds'));
    }

    private function verifier(): PaymentVerifier
    {
        return new PaymentVerifier($this->attempts, $this->gateways, AuditFixtures::trail($this->audit), $this->clock, $this->logger);
    }

    private function answer(PaymentOutcome $outcome, ?Money $amount = null, ?Money $fee = null): void
    {
        $this->gateways->verifyAnswer = static fn (PaymentReference $r): GatewayVerification => new GatewayVerification(
            $r,
            $outcome,
            $amount,
            $outcome === PaymentOutcome::Succeeded ? '4099260516' : null,
            new \DateTimeImmutable('2026-10-31T10:01:30Z'),
            $fee,
        );
    }

    private function state(string $id = self::ATTEMPT): string
    {
        return $this->attempts->attempts[$id]['state'];
    }

    private function invoiceStatus(): InvoiceStatus
    {
        return $this->attempts->invoices[self::ORDER]['invoice']->status;
    }

    public function testAConfirmedPaymentOfTheExactAmountMarksTheInvoicePaid(): void
    {
        $this->answer(PaymentOutcome::Succeeded, Money::of(1_234_500, 'NGN'), Money::of(18_518, 'NGN'));

        self::assertSame(1, $this->verifier()->runOnce());

        self::assertSame('succeeded', $this->state());
        self::assertSame(InvoiceStatus::Paid, $this->invoiceStatus());
        self::assertSame([
            ['kind' => 'payment', 'payment' => self::ATTEMPT, 'minor' => 1_234_500, 'currency' => 'NGN'],
            ['kind' => 'gateway_fee', 'payment' => self::ATTEMPT, 'minor' => 18_518, 'currency' => 'NGN'],
        ], $this->attempts->postings);
        self::assertSame([AuditEventType::PAYMENT_SUCCEEDED], $this->audit->types());
    }

    public function testASettledAttemptIsNeitherCheckedNorPostedAgain(): void
    {
        $this->answer(PaymentOutcome::Succeeded, Money::of(1_234_500, 'NGN'));
        $this->verifier()->runOnce();

        $this->clock->advance('+1 hour');
        self::assertSame(0, $this->verifier()->runOnce());
        self::assertCount(1, $this->attempts->postings);
        self::assertCount(1, $this->gateways->verified);
    }

    public function testLessThanTheInvoiceIsAMismatchAndTheInvoiceStaysUnpaid(): void
    {
        $this->answer(PaymentOutcome::Succeeded, Money::of(1_234_499, 'NGN'));

        $this->verifier()->runOnce();

        self::assertSame('amount_mismatch', $this->state());
        self::assertSame(InvoiceStatus::Unpaid, $this->invoiceStatus(), 'PT-03');
        self::assertSame([['kind' => 'unallocated', 'payment' => self::ATTEMPT, 'minor' => 1_234_499, 'currency' => 'NGN']], $this->attempts->postings);
        self::assertSame([AuditEventType::PAYMENT_AMOUNT_MISMATCH], $this->audit->types());
        self::assertSame(1_234_499, $this->audit->events[0]->details['paid_minor']);
    }

    public function testTheSameNumberInAnotherCurrencyIsAMismatch(): void
    {
        $this->answer(PaymentOutcome::Succeeded, Money::of(1_234_500, 'USD'));

        $this->verifier()->runOnce();

        self::assertSame('amount_mismatch', $this->state());
        self::assertSame(InvoiceStatus::Unpaid, $this->invoiceStatus(), 'PT-04');
        self::assertSame('usd', $this->audit->events[0]->details['paid_currency']);
    }

    public function testASecondPaymentForAPaidInvoiceGoesToTheCustomersCredit(): void
    {
        $this->pending(self::SECOND, Gateway::Flutterwave);
        $this->answer(PaymentOutcome::Succeeded, Money::of(1_234_500, 'NGN'));

        self::assertSame(2, $this->verifier()->runOnce());

        self::assertSame('succeeded', $this->state(self::ATTEMPT));
        self::assertSame('succeeded', $this->state(self::SECOND));
        self::assertSame(['payment', 'overpayment'], array_column($this->attempts->postings, 'kind'), 'PT-07: never paid twice');
        self::assertSame([AuditEventType::PAYMENT_SUCCEEDED, AuditEventType::PAYMENT_CREDITED], $this->audit->types());
    }

    public function testAnOpenPaymentIsCheckedAgainOnTheSchedule(): void
    {
        $this->answer(PaymentOutcome::Pending);

        $this->verifier()->runOnce();

        self::assertSame('pending', $this->state());
        self::assertSame(1, $this->attempts->attempts[self::ATTEMPT]['checks']);
        self::assertEquals($this->created->modify('+600 seconds'), $this->attempts->attempts[self::ATTEMPT]['next']);
        self::assertSame(0, $this->verifier()->runOnce(), 'not due again until 10 minutes after creation');
        self::assertSame([], $this->attempts->postings);
    }

    public function testAGatewayThatCannotAnswerMakesTheAttemptUnknownAndKeepsChecking(): void
    {
        $this->gateways->verifyAnswer = static fn (): \Throwable => new GatewayUnavailable('transport');

        $this->verifier()->runOnce();

        self::assertSame('unknown', $this->state());
        self::assertSame(InvoiceStatus::Unpaid, $this->invoiceStatus(), 'PT-21');

        $this->clock->advance('+8 minutes');
        $this->answer(PaymentOutcome::Succeeded, Money::of(1_234_500, 'NGN'));
        $this->verifier()->runOnce();
        self::assertSame('succeeded', $this->state());
    }

    public function testAFailedAnswerWaitsForTheLastCheckBeforeClosing(): void
    {
        $this->answer(PaymentOutcome::Failed);
        $seen = [];
        foreach (PaymentVerifier::SCHEDULE_SECONDS as $offset) {
            $this->clock->setTo($this->created->modify('+' . $offset . ' seconds'));
            $this->verifier()->runOnce();
            $seen[] = $this->state();
        }

        self::assertSame(['pending', 'pending', 'pending', 'pending', 'failed'], $seen);
        self::assertSame([AuditEventType::PAYMENT_FAILED], $this->audit->types());
        self::assertNull($this->attempts->attempts[self::ATTEMPT]['next']);
    }

    public function testNoAnswerAfterADayIsAbandoned(): void
    {
        $this->answer(PaymentOutcome::Abandoned);
        foreach (PaymentVerifier::SCHEDULE_SECONDS as $offset) {
            $this->clock->setTo($this->created->modify('+' . $offset . ' seconds'));
            $this->verifier()->runOnce();
        }

        self::assertSame('abandoned', $this->state());
        self::assertSame([AuditEventType::PAYMENT_ABANDONED], $this->audit->types());
        self::assertCount(5, $this->gateways->verified, 'bounded: exactly five checks');
    }

    public function testAnAnswerAboutAnotherReferenceIsNotTrusted(): void
    {
        $this->gateways->verifyAnswer = fn (): GatewayVerification => new GatewayVerification(
            PaymentReference::generate($this->created), PaymentOutcome::Succeeded, Money::of(1_234_500, 'NGN'), '4099260516',
        );

        $this->verifier()->runOnce();

        self::assertSame('unknown', $this->state());
        self::assertSame([], $this->attempts->postings);
    }

    public function testARecordingErrorIsRetriedOnTheScheduleNotEveryLoop(): void
    {
        $this->answer(PaymentOutcome::Succeeded, Money::of(1_234_500, 'NGN'));
        $this->attempts->failNextRecord = true;

        $this->verifier()->runOnce();

        self::assertSame('unknown', $this->state());
        self::assertSame(0, $this->verifier()->runOnce());
        self::assertSame('payments.record_error', $this->logger->records[0]['message'] ?? null);
    }

    public function testGatewayTextNeverReachesTheLogs(): void
    {
        $this->gateways->verifyAnswer = static fn (): \Throwable => new \RuntimeException('card 4111 for owner@example.invalid');

        $this->verifier()->runOnce();

        $logged = json_encode($this->logger->records, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('4111', $logged);
        self::assertStringNotContainsString('owner@example.invalid', $logged);
    }
}
