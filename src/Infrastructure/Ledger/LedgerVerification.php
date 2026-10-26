<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Ledger;

/** What `ledger:verify` found. Problems name entry IDs and fixed reasons only, never amounts or customers. */
final readonly class LedgerVerification
{
    /** @param list<string> $problems */
    public function __construct(
        public int $entries,
        public int $lines,
        public int $invoices,
        public array $problems,
    ) {
    }

    public function ok(): bool
    {
        return $this->problems === [];
    }
}
