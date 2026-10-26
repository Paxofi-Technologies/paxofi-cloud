<?php

declare(strict_types=1);

namespace PaxofiCloud\Domain\Ledger;

/** One side of an entry: a debit or a credit in integer minor units, never both (PY-13). */
final readonly class JournalLine
{
    /** Same bound as Money (±10^13 minor units). */
    public const int MAX_MINOR = 10_000_000_000_000;

    private function __construct(
        public LedgerAccount $account,
        public ?string $customerAccountId,
        public int $debitMinor,
        public int $creditMinor,
    ) {
        $amount = max($debitMinor, $creditMinor);
        if (min($debitMinor, $creditMinor) !== 0 || $amount <= 0 || $amount > self::MAX_MINOR) {
            throw new LedgerRuleBroken('A line is a positive debit or a positive credit, within bounds.');
        }
        if ($account->perCustomer() !== ($customerAccountId !== null)) {
            throw new LedgerRuleBroken('Receivable and customer credit lines name a customer account; other lines do not.');
        }
        if ($customerAccountId !== null && preg_match('/^[0-9a-f]{32}$/', $customerAccountId) !== 1) {
            throw new LedgerRuleBroken('Customer account IDs are 32 lowercase hex characters.');
        }
    }

    public static function debit(LedgerAccount $account, int $minor, ?string $customerAccountId = null): self
    {
        return new self($account, $customerAccountId, $minor, 0);
    }

    public static function credit(LedgerAccount $account, int $minor, ?string $customerAccountId = null): self
    {
        return new self($account, $customerAccountId, 0, $minor);
    }

    public function reversed(): self
    {
        return new self($this->account, $this->customerAccountId, $this->creditMinor, $this->debitMinor);
    }
}
