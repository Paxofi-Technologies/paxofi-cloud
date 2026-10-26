<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Contracts;

use PaxofiCloud\Domain\Ledger\JournalEntry;
use PaxofiCloud\Domain\Ledger\LedgerAccount;
use PaxofiCloud\Domain\Money\Currency;

/**
 * The append-only journal (payments threat model PY-13). Posting a source key
 * that is already posted changes nothing and returns false, which is how
 * webhook duplicates and retries stay exactly-once (PY-9).
 */
interface Ledger
{
    /** Posts the entry in its own transaction. */
    public function post(JournalEntry $entry): bool;

    /** Posts the entry inside the caller's open transaction, so it commits or rolls back with the business change it records. */
    public function postInCurrentTransaction(JournalEntry $entry): bool;

    /** Net balance, debits minus credits, in minor units; per-customer accounts need the customer account ID. */
    public function balance(LedgerAccount $account, Currency $currency, ?string $customerAccountId = null): int;
}
