<?php

declare(strict_types=1);

namespace PaxofiCloud\Cli;

use Paxofi\Core\Contracts\Command;
use PaxofiCloud\Application\Contracts\Clock;
use PaxofiCloud\Infrastructure\Audit\MysqlAuditChain;

/**
 * `bin/paxoficloud audit:purge-context` — deletes request context (IP,
 * location, user agent) older than 180 days (threat model T-51). The events
 * and the chain stay intact.
 */
final readonly class AuditPurgeContextCommand implements Command
{
    public function __construct(private MysqlAuditChain $chain, private Console $console, private Clock $clock)
    {
    }

    public function name(): string
    {
        return 'audit:purge-context';
    }

    public function execute(array $arguments): int
    {
        if ($arguments !== []) {
            $this->console->error('audit:purge-context takes no arguments; retention is fixed at ' . MysqlAuditChain::CONTEXT_RETENTION_DAYS . ' days.');

            return 1;
        }
        $cutoff = $this->clock->now()->modify('-' . MysqlAuditChain::CONTEXT_RETENTION_DAYS . ' days');
        $this->console->line(sprintf('audit context purged rows=%d', $this->chain->purgeContext($cutoff)));

        return 0;
    }
}
