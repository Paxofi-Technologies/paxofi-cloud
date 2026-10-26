<?php

declare(strict_types=1);

namespace PaxofiCloud\Cli;

use Paxofi\Core\Contracts\Command;
use PaxofiCloud\Infrastructure\Ledger\MysqlLedger;

/**
 * `bin/paxoficloud ledger:verify` — checks the rules the database cannot
 * (payments threat model PY-13, PT-12, PT-22): every entry balanced with at
 * least two lines, reversals matching what they reverse, and every invoice
 * carrying its issue entry. Exit 0 with counts; exit 2 with the problems
 * (entry or invoice IDs and fixed reasons, never amounts or customers).
 */
final readonly class LedgerVerifyCommand implements Command
{
    public function __construct(private MysqlLedger $ledger, private Console $console)
    {
    }

    public function name(): string
    {
        return 'ledger:verify';
    }

    public function execute(array $arguments): int
    {
        $result = $this->ledger->verify();
        if (!$result->ok()) {
            foreach ($result->problems as $problem) {
                $this->console->error('ledger problem: ' . $problem);
            }
            $this->console->error(sprintf('ledger BROKEN problems=%d entries=%d lines=%d invoices=%d', count($result->problems), $result->entries, $result->lines, $result->invoices));

            return 2;
        }
        $this->console->line(sprintf('ledger OK entries=%d lines=%d invoices=%d', $result->entries, $result->lines, $result->invoices));

        return 0;
    }
}
