<?php

declare(strict_types=1);

namespace PaxofiCloud\Cli;

use Paxofi\Core\Contracts\Command;
use PaxofiCloud\Application\Registrar\DomainCostSync;

/**
 * `bin/paxoficloud prices:sync [--provider=domainnameapi|namecheap|dynadot]` — pulls
 * a registrar's domain cost list now (THREAT-MODEL-DOMAIN-REGISTRAR.md DR-8,
 * DR-10; NC-9). Runs only where the provider credential keys are, i.e. in
 * the provider worker. Prints fixed codes and counts only.
 *
 * Exit codes: 0 accepted, 1 failed, 2 quarantined, 3 another run in progress.
 */
final readonly class PricesSyncCommand implements Command
{
    public const array PROVIDERS = ['domainnameapi', 'namecheap', 'dynadot'];

    /** @param \Closure(string): DomainCostSync $sync built lazily per registrar so keys are read only when it runs */
    public function __construct(private \Closure $sync, private Console $console)
    {
    }

    public function name(): string
    {
        return 'prices:sync';
    }

    public function execute(array $arguments): int
    {
        $provider = self::PROVIDERS[0];
        foreach ($arguments as $argument) {
            if (preg_match('/^--provider=([a-z]{1,32})$/', (string) $argument, $m) === 1) {
                $provider = $m[1];
            }
        }
        if (!in_array($provider, self::PROVIDERS, true)) {
            $this->console->error('Unknown provider; use one of: ' . implode(', ', self::PROVIDERS) . '.');

            return 1;
        }
        try {
            $run = ($this->sync)($provider)->run('cli');
        } catch (\InvalidArgumentException $e) {
            $this->console->error('Price sync is not configured: ' . $e->getMessage());

            return 1;
        }
        if ($run === null) {
            $this->console->line('another price sync is already running');

            return 3;
        }
        $this->console->line(sprintf('%s outcome=%s reason=%s attempts=%d', $run->provider, $run->outcome, $run->reason ?? '-', $run->attempts));

        return match ($run->outcome) {
            'accepted' => 0,
            'quarantined' => 2,
            default => 1,
        };
    }
}
