<?php

declare(strict_types=1);

namespace PaxofiCloud\Domain\Ledger;

use PaxofiCloud\Domain\Money\Currency;
use PaxofiCloud\Domain\Money\Money;
use PaxofiCloud\Domain\Payments\Gateway;

/** The entries the business posts (PY-13). Amounts are invoice amounts in the invoice currency. */
final class Postings
{
    private function __construct()
    {
    }

    /** Invoice issued: DR receivable (customer) total / CR domain revenue subtotal / CR tax payable tax. */
    public static function invoiceIssued(
        string $entryId,
        string $invoiceId,
        string $customerAccountId,
        Currency $currency,
        int $subtotalMinor,
        int $taxMinor,
        \DateTimeImmutable $issuedAt,
    ): JournalEntry {
        if ($subtotalMinor <= 0 || $taxMinor < 0) {
            throw new LedgerRuleBroken('An invoice has a positive subtotal and no negative tax.');
        }
        $lines = [
            JournalLine::debit(LedgerAccount::Receivable, $subtotalMinor + $taxMinor, $customerAccountId),
            JournalLine::credit(LedgerAccount::RevenueDomains, $subtotalMinor),
        ];
        if ($taxMinor > 0) {
            $lines[] = JournalLine::credit(LedgerAccount::TaxPayable, $taxMinor);
        }

        return new JournalEntry($entryId, self::invoiceIssuedKey($invoiceId), EntryKind::InvoiceIssued, $currency, $issuedAt, $lines);
    }

    public static function invoiceIssuedKey(string $invoiceId): string
    {
        return EntryKind::InvoiceIssued->value . ':' . $invoiceId;
    }

    /** Payment applied to an invoice (PY-9): DR the gateway's clearing account / CR the customer's receivable. */
    public static function payment(string $entryId, string $attemptId, string $customerAccountId, Gateway $gateway, Money $amount, \DateTimeImmutable $at): JournalEntry
    {
        return new JournalEntry($entryId, EntryKind::Payment->value . ':' . $attemptId, EntryKind::Payment, $amount->currency, $at, [
            JournalLine::debit(self::clearing($gateway), self::positive($amount)),
            JournalLine::credit(LedgerAccount::Receivable, $amount->minorUnits, $customerAccountId),
        ]);
    }

    /** The gateway's fee from its verify answer (LED-004): DR gateway fees / CR the gateway's clearing account. */
    public static function gatewayFee(string $entryId, string $attemptId, Gateway $gateway, Money $fee, \DateTimeImmutable $at): JournalEntry
    {
        return new JournalEntry($entryId, EntryKind::GatewayFee->value . ':' . $attemptId, EntryKind::GatewayFee, $fee->currency, $at, [
            JournalLine::debit(LedgerAccount::GatewayFees, self::positive($fee)),
            JournalLine::credit(self::clearing($gateway), $fee->minorUnits),
        ]);
    }

    /**
     * Real money for an invoice that is no longer unpaid, such as a second
     * payment (PY-12, BR-18): DR clearing / CR the customer's credit.
     */
    public static function overpayment(string $entryId, string $attemptId, string $customerAccountId, Gateway $gateway, Money $amount, \DateTimeImmutable $at): JournalEntry
    {
        return new JournalEntry($entryId, EntryKind::Overpayment->value . ':' . $attemptId, EntryKind::Overpayment, $amount->currency, $at, [
            JournalLine::debit(self::clearing($gateway), self::positive($amount)),
            JournalLine::credit(LedgerAccount::CustomerCredit, $amount->minorUnits, $customerAccountId),
        ]);
    }

    /** A success whose amount or currency differs from the attempt (PY-8): DR clearing / CR unallocated receipts, invoice untouched. */
    public static function unallocated(string $entryId, string $attemptId, Gateway $gateway, Money $amount, \DateTimeImmutable $at): JournalEntry
    {
        return new JournalEntry($entryId, EntryKind::Unallocated->value . ':' . $attemptId, EntryKind::Unallocated, $amount->currency, $at, [
            JournalLine::debit(self::clearing($gateway), self::positive($amount)),
            JournalLine::credit(LedgerAccount::UnallocatedReceipts, $amount->minorUnits),
        ]);
    }

    public static function clearing(Gateway $gateway): LedgerAccount
    {
        return match ($gateway) {
            Gateway::Paystack => LedgerAccount::ClearingPaystack,
            Gateway::Flutterwave => LedgerAccount::ClearingFlutterwave,
        };
    }

    private static function positive(Money $amount): int
    {
        if ($amount->minorUnits <= 0) {
            throw new LedgerRuleBroken('A posting is for a positive amount.');
        }

        return $amount->minorUnits;
    }
}
