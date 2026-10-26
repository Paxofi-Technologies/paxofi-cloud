<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Domain\Ledger;

use PaxofiCloud\Domain\Ledger\EntryKind;
use PaxofiCloud\Domain\Ledger\JournalEntry;
use PaxofiCloud\Domain\Ledger\JournalLine;
use PaxofiCloud\Domain\Ledger\LedgerAccount;
use PaxofiCloud\Domain\Ledger\LedgerRuleBroken;
use PaxofiCloud\Domain\Ledger\Postings;
use PaxofiCloud\Domain\Money\Currency;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Ledger rules in the domain (payments threat model PY-13, PT-12, PT-22; LED-001, LED-003). */
final class JournalEntryTest extends TestCase
{
    private const string CUSTOMER = 'c1000000000000000000000000000001';

    public function testInvoiceIssuedPostsReceivableRevenueAndTax(): void
    {
        $entry = Postings::invoiceIssued(str_repeat('1', 32), str_repeat('a', 32), self::CUSTOMER, Currency::NGN, 1_650_000, 123_750, new \DateTimeImmutable());

        self::assertSame('invoice_issued:' . str_repeat('a', 32), $entry->sourceKey);
        self::assertSame(1_773_750, $entry->totalMinor());
        self::assertEquals(
            [
                JournalLine::debit(LedgerAccount::Receivable, 1_773_750, self::CUSTOMER),
                JournalLine::credit(LedgerAccount::RevenueDomains, 1_650_000),
                JournalLine::credit(LedgerAccount::TaxPayable, 123_750),
            ],
            $entry->lines,
        );
    }

    public function testNoTaxLineWhenTaxIsZero(): void
    {
        $entry = Postings::invoiceIssued(str_repeat('1', 32), str_repeat('a', 32), self::CUSTOMER, Currency::USD, 1_000, 0, new \DateTimeImmutable());

        self::assertCount(2, $entry->lines);
    }

    public function testReversalSwapsSidesAndIsKeyedByTheOriginal(): void
    {
        $entry = Postings::invoiceIssued(str_repeat('1', 32), str_repeat('a', 32), self::CUSTOMER, Currency::NGN, 1_000, 75, new \DateTimeImmutable());
        $reversal = $entry->reversal(str_repeat('2', 32), new \DateTimeImmutable());

        self::assertSame(EntryKind::Reversal, $reversal->kind);
        self::assertSame('reversal:' . str_repeat('1', 32), $reversal->sourceKey);
        self::assertSame($entry->id, $reversal->reversesEntryId);
        self::assertSame(1_075, $reversal->lines[0]->creditMinor);
        self::assertSame(0, $reversal->lines[0]->debitMinor);

        $this->expectException(LedgerRuleBroken::class);
        $reversal->reversal(str_repeat('3', 32), new \DateTimeImmutable());
    }

    /** @return iterable<string, array{callable(): mixed}> */
    public static function brokenEntries(): iterable
    {
        $at = new \DateTimeImmutable();
        $dr = JournalLine::debit(LedgerAccount::ClearingPaystack, 100);
        $cr = JournalLine::credit(LedgerAccount::Receivable, 100, self::CUSTOMER);
        $id = str_repeat('1', 32);

        yield 'unbalanced' => [static fn (): JournalEntry => new JournalEntry($id, 'payment:x', EntryKind::Payment, Currency::NGN, $at, [$dr, JournalLine::credit(LedgerAccount::Receivable, 99, self::CUSTOMER)])];
        yield 'one line' => [static fn (): JournalEntry => new JournalEntry($id, 'payment:x', EntryKind::Payment, Currency::NGN, $at, [$dr])];
        yield 'source key of another kind' => [static fn (): JournalEntry => new JournalEntry($id, 'refund:x', EntryKind::Payment, Currency::NGN, $at, [$dr, $cr])];
        yield 'source key with spaces' => [static fn (): JournalEntry => new JournalEntry($id, 'payment:a b', EntryKind::Payment, Currency::NGN, $at, [$dr, $cr])];
        yield 'bad id' => [static fn (): JournalEntry => new JournalEntry('X', 'payment:x', EntryKind::Payment, Currency::NGN, $at, [$dr, $cr])];
        yield 'reversal without original' => [static fn (): JournalEntry => new JournalEntry($id, 'reversal:' . $id, EntryKind::Reversal, Currency::NGN, $at, [$dr, $cr])];
        yield 'reversal keyed to another entry' => [static fn (): JournalEntry => new JournalEntry($id, 'reversal:' . str_repeat('9', 32), EntryKind::Reversal, Currency::NGN, $at, [$dr, $cr], str_repeat('8', 32))];
        yield 'non-reversal naming an original' => [static fn (): JournalEntry => new JournalEntry($id, 'payment:x', EntryKind::Payment, Currency::NGN, $at, [$dr, $cr], str_repeat('8', 32))];
        yield 'zero line' => [static fn (): JournalLine => JournalLine::debit(LedgerAccount::ClearingPaystack, 0)];
        yield 'negative line' => [static fn (): JournalLine => JournalLine::credit(LedgerAccount::ClearingPaystack, -5)];
        yield 'over the bound' => [static fn (): JournalLine => JournalLine::debit(LedgerAccount::ClearingPaystack, JournalLine::MAX_MINOR + 1)];
        yield 'receivable without customer' => [static fn (): JournalLine => JournalLine::debit(LedgerAccount::Receivable, 100)];
        yield 'credit sub-ledger without customer' => [static fn (): JournalLine => JournalLine::credit(LedgerAccount::CustomerCredit, 100)];
        yield 'revenue with customer' => [static fn (): JournalLine => JournalLine::credit(LedgerAccount::RevenueDomains, 100, self::CUSTOMER)];
        yield 'malformed customer' => [static fn (): JournalLine => JournalLine::debit(LedgerAccount::Receivable, 100, 'ALICE')];
        yield 'invoice without subtotal' => [static fn (): JournalEntry => Postings::invoiceIssued($id, str_repeat('a', 32), self::CUSTOMER, Currency::NGN, 0, 0, $at)];
        yield 'negative tax' => [static fn (): JournalEntry => Postings::invoiceIssued($id, str_repeat('a', 32), self::CUSTOMER, Currency::NGN, 100, -1, $at)];
    }

    /** @param callable(): mixed $build */
    #[DataProvider('brokenEntries')]
    public function testBrokenEntriesAreRefused(callable $build): void
    {
        $this->expectException(LedgerRuleBroken::class);
        $build();
    }

    public function testFiftyLinesAreAllowedAndFiftyOneAreNot(): void
    {
        $lines = array_merge(array_fill(0, 49, JournalLine::debit(LedgerAccount::ClearingPaystack, 1)), [JournalLine::credit(LedgerAccount::UnallocatedReceipts, 49)]);
        self::assertCount(50, (new JournalEntry(str_repeat('1', 32), 'unallocated:x', EntryKind::Unallocated, Currency::NGN, new \DateTimeImmutable(), $lines))->lines);

        $this->expectException(LedgerRuleBroken::class);
        $balanced51 = array_merge(array_fill(0, 50, JournalLine::debit(LedgerAccount::ClearingPaystack, 1)), [JournalLine::credit(LedgerAccount::UnallocatedReceipts, 50)]);
        new JournalEntry(str_repeat('1', 32), 'unallocated:x', EntryKind::Unallocated, Currency::NGN, new \DateTimeImmutable(), $balanced51);
    }
}
