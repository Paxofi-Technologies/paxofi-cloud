<?php

declare(strict_types=1);

namespace PaxofiCloud\Cli;

use Paxofi\Core\Contracts\Command;
use PaxofiCloud\Application\Contracts\DomainCostStore;

/**
 * `bin/paxoficloud prices:status` — per registrar, the latest domain cost
 * sync runs and the size of the newest accepted snapshot. Fixed codes, times
 * and counts only, so it is safe for the public deploy log.
 */
final readonly class PricesStatusCommand implements Command
{
    private const int RUNS = 5;

    /** @param non-empty-array<string, string> $environments provider => environment its prices come from */
    public function __construct(private DomainCostStore $store, private Console $console, private array $environments)
    {
    }

    public function name(): string
    {
        return 'prices:status';
    }

    public function execute(array $arguments): int
    {
        foreach ($this->environments as $provider => $environment) {
            $this->report($provider, $environment);
        }

        return 0;
    }

    private function report(string $provider, string $environment): void
    {
        $latest = $this->store->latestAccepted($provider, $environment);
        $this->console->line(sprintf('%s environment=%s accepted_extensions=%s', $provider, $environment, $latest === null ? 'none' : (string) count($latest)));
        $runs = $this->store->recentRuns($provider, self::RUNS);
        if ($runs === []) {
            $this->console->line('no price sync has run yet');

            return;
        }
        foreach ($runs as $run) {
            $this->console->line(sprintf(
                '%s trigger=%s outcome=%s reason=%s attempts=%d',
                $run->startedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
                $run->trigger,
                $run->outcome,
                $run->reason ?? '-',
                $run->attempts,
            ));
        }
    }
}
