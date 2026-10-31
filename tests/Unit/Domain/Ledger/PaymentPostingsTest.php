<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Domain\Ledger;

use PaxofiCloud\Domain\Ledger\EntryKind;
use PaxofiCloud\Domain\Ledger\JournalEntry;
use PaxofiCloud\Domain\Ledger\LedgerAccount;
use PaxofiCloud\Domain\Ledger\LedgerRuleBroken;
use PaxofiCloud\Domain\Ledger\Postings;
use PaxofiCloud\Domain\Money\Money;
use PaxofiCloud\Domain\Payments\Gateway;
use PHPUnit\Framework\TestCase;

/** THREAT-MODEL-PAYMENTS.md PY-8, PY-9, PY-12, PY-13; LED-001, LED-004. */
final class PaymentPostingsTest extends TestCase
{
    private const string ENTRY = '0123456789abcdef0123456789abcdef';
    private const string ATTEMPT = 'ffffffffffffffffffffffffffffffff';
    private const string CUSTOMER = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /** @return list<array{string, ?string, int, int}> */
    private static function lines(JournalEntry $entry): array
    {
        return array_map(static fn ($l): array => [$l->account->value, $l->customerAccountId, $l->debitMinor, $l->creditMinor], $entry->lines);
    }

    private static function at(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-10-31T10:00:00Z');
    }

    public function testAPaymentClearsTheCustomersReceivable(): void
    {
        $entry = Postings::payment(self::ENTRY, self::ATTEMPT, self::CUSTOMER, Gateway::Paystack, Money::of(1_234_500, 'NGN'), self::at());

        self::assertSame('payment:' . self::ATTEMPT, $entry->sourceKey);
        self::assertSame(EntryKind::Payment, $entry->kind);
        self::assertSame([
            ['clearing_paystack', null, 1_234_500, 0],
            ['receivable', self::CUSTOMER, 0, 1_234_500],
        ], self::lines($entry));
    }

    public function testEachGatewayHasItsOwnClearingAccount(): void
    {
        $entry = Postings::payment(self::ENTRY, self::ATTEMPT, self::CUSTOMER, Gateway::Flutterwave, Money::of(500, 'NGN'), self::at());

        self::assertSame(LedgerAccount::ClearingFlutterwave, $entry->lines[0]->account);
    }

    public function testTheFeeMovesFromClearingToExpense(): void
    {
        $entry = Postings::gatewayFee(self::ENTRY, self::ATTEMPT, Gateway::Paystack, Money::of(18_518, 'NGN'), self::at());

        self::assertSame('gateway_fee:' . self::ATTEMPT, $entry->sourceKey);
        self::assertSame([['gateway_fees', null, 18_518, 0], ['clearing_paystack', null, 0, 18_518]], self::lines($entry));
    }

    public function testASecondPaymentBecomesCustomerCredit(): void
    {
        $entry = Postings::overpayment(self::ENTRY, self::ATTEMPT, self::CUSTOMER, Gateway::Flutterwave, Money::of(1_000, 'NGN'), self::at());

        self::assertSame('overpayment:' . self::ATTEMPT, $entry->sourceKey);
        self::assertSame([['clearing_flutterwave', null, 1_000, 0], ['customer_credit', self::CUSTOMER, 0, 1_000]], self::lines($entry));
    }

    public function testAMismatchIsUnallocatedInThePaidCurrency(): void
    {
        $entry = Postings::unallocated(self::ENTRY, self::ATTEMPT, Gateway::Paystack, Money::of(700, 'USD'), self::at());

        self::assertSame('unallocated:' . self::ATTEMPT, $entry->sourceKey);
        self::assertSame('USD', $entry->currency->value);
        self::assertSame([['clearing_paystack', null, 700, 0], ['unallocated_receipts', null, 0, 700]], self::lines($entry));
    }

    public function testNothingIsPostedForZero(): void
    {
        $this->expectException(LedgerRuleBroken::class);
        Postings::payment(self::ENTRY, self::ATTEMPT, self::CUSTOMER, Gateway::Paystack, Money::of(0, 'NGN'), self::at());
    }
}
