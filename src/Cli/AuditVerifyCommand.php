<?php

declare(strict_types=1);

namespace PaxofiCloud\Cli;

use Paxofi\Core\Contracts\Command;
use PaxofiCloud\Infrastructure\Audit\MysqlAuditChain;

/**
 * `bin/paxoficloud audit:verify` — walks the audit chain (threat model T-39).
 * Exit 0 with the event count and head hash (record the hash elsewhere as an
 * anchor); exit 2 with the first broken sequence number.
 */
final readonly class AuditVerifyCommand implements Command
{
    public function __construct(private MysqlAuditChain $chain, private Console $console)
    {
    }

    public function name(): string
    {
        return 'audit:verify';
    }

    public function execute(array $arguments): int
    {
        $result = $this->chain->verify();
        if (!$result->ok()) {
            $this->console->error(sprintf('audit chain BROKEN at seq=%d: %s (verified %d events before it)', (int) $result->brokenAt, $result->reason, $result->events));

            return 2;
        }
        $this->console->line(sprintf('audit chain OK events=%d head=%s context_purged=%d', $result->events, $result->headHash, $result->contextsPurged));

        return 0;
    }
}
