<?php

declare(strict_types=1);

namespace PaxofiCloud\Domain\Ledger;

use PaxofiCloud\Domain\Money\Currency;

/**
 * A balanced, single-currency journal entry (PY-13, LED-001). Every entry has
 * a unique source key, so posting the same source twice is refused by the
 * store rather than counted twice (PY-9). Entries are never edited;
 * corrections are reversing entries.
 */
final readonly class JournalEntry
{
    public const int MAX_LINES = 50;
    private const string ID = '/^[0-9a-f]{32}$/';
    private const string SOURCE_KEY = '/^[a-z_]{3,24}:[0-9a-z_:-]{1,96}$/';

    /** @param list<JournalLine> $lines */
    public function __construct(
        public string $id,
        public string $sourceKey,
        public EntryKind $kind,
        public Currency $currency,
        public \DateTimeImmutable $postedAt,
        public array $lines,
        public ?string $reversesEntryId = null,
    ) {
        if (preg_match(self::ID, $id) !== 1 || ($reversesEntryId !== null && preg_match(self::ID, $reversesEntryId) !== 1)) {
            throw new LedgerRuleBroken('Entry IDs are 32 lowercase hex characters.');
        }
        if (preg_match(self::SOURCE_KEY, $sourceKey) !== 1 || !str_starts_with($sourceKey, $kind->value . ':')) {
            throw new LedgerRuleBroken('The source key starts with the entry kind and uses a fixed alphabet.');
        }
        if (($kind === EntryKind::Reversal) !== ($reversesEntryId !== null)
            || ($reversesEntryId !== null && $sourceKey !== self::reversalKey($reversesEntryId))) {
            throw new LedgerRuleBroken('Only reversal entries name the entry they reverse, keyed by that entry\'s ID.');
        }
        if (count($lines) < 2 || count($lines) > self::MAX_LINES) {
            throw new LedgerRuleBroken('An entry has 2 to 50 lines.');
        }
        if (self::debits($lines) !== self::credits($lines)) {
            throw new LedgerRuleBroken('Debits must equal credits.');
        }
    }

    public function totalMinor(): int
    {
        return self::debits($this->lines);
    }

    /** The same lines with debit and credit swapped (PY-16 cancellation, corrections). */
    public function reversal(string $id, \DateTimeImmutable $postedAt): self
    {
        if ($this->kind === EntryKind::Reversal) {
            throw new LedgerRuleBroken('A reversal is not reversed; post a new entry instead.');
        }

        return new self($id, self::reversalKey($this->id), EntryKind::Reversal, $this->currency, $postedAt, array_map(static fn (JournalLine $l): JournalLine => $l->reversed(), $this->lines), $this->id);
    }

    public static function reversalKey(string $entryId): string
    {
        return EntryKind::Reversal->value . ':' . $entryId;
    }

    /** @param list<JournalLine> $lines */
    private static function debits(array $lines): int
    {
        return array_sum(array_map(static fn (JournalLine $l): int => $l->debitMinor, $lines));
    }

    /** @param list<JournalLine> $lines */
    private static function credits(array $lines): int
    {
        return array_sum(array_map(static fn (JournalLine $l): int => $l->creditMinor, $lines));
    }
}
