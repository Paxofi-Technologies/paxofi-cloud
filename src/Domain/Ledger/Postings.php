<?php

declare(strict_types=1);

namespace PaxofiCloud\Domain\Ledger;

use PaxofiCloud\Domain\Money\Currency;

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
}
