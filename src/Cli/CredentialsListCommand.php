<?php

declare(strict_types=1);

namespace PaxofiCloud\Cli;

use Paxofi\Core\Contracts\Command;
use PaxofiCloud\Application\Contracts\Clock;
use PaxofiCloud\Infrastructure\Secrets\InvalidKeyRing;

/**
 * `bin/paxoficloud credentials:list` — metadata only (never envelopes or
 * secrets). Exits 2 when a credential is overdue for review or still on a
 * retired key, so monitoring can alert on it.
 */
final readonly class CredentialsListCommand implements Command
{
    public function __construct(
        private CredentialStoreAccess $access,
        private Console $console,
        private Clock $clock,
    ) {
    }

    public function name(): string
    {
        return 'credentials:list';
    }

    public function execute(array $arguments): int
    {
        try {
            $store = $this->access->store();
        } catch (InvalidKeyRing $error) {
            $this->console->error($error->getMessage());

            return 1;
        }

        $summaries = $store->summaries();
        if ($summaries === []) {
            $this->console->line('No provider credentials.');

            return 0;
        }

        $today = $this->clock->now();
        $exit = 0;
        $this->console->line(sprintf('%-32s %-14s %-8s %-10s %-12s %-10s %s', 'ID', 'PROVIDER', 'ENV', 'ACCESS', 'KEY', 'REVIEW', 'STATUS'));
        foreach ($summaries as $summary) {
            $problems = [];
            if ($summary->isReviewOverdue($today)) {
                $problems[] = 'review overdue';
            }
            if ($store->needsReencryption($summary)) {
                $problems[] = 'old key';
            }
            if ($problems !== []) {
                $exit = 2;
            }
            $credential = $summary->credential;
            $this->console->line(sprintf(
                '%-32s %-14s %-8s %-10s %-12s %-10s %s',
                $credential->id,
                $credential->provider,
                $credential->environment,
                $credential->accessLevel,
                $summary->keyId,
                $summary->reviewDueOn->format('Y-m-d'),
                $problems === [] ? 'ok' : implode(', ', $problems),
            ));
        }

        return $exit;
    }
}
