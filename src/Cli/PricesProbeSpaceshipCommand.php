<?php

declare(strict_types=1);

namespace PaxofiCloud\Cli;

use Paxofi\Core\Contracts\Command;
use PaxofiCloud\Application\Registrar\SpaceshipPriceCheck;

/**
 * `bin/paxoficloud prices:probe-spaceship` — runs the Spaceship price check
 * now (THREAT-MODEL-DOMAIN-PRICING.md SP-3): one availability request for 20
 * random test names, registering nothing. Runs only where the credential keys
 * are, i.e. in the provider worker. Prints counts and fixed codes only.
 *
 * Exit codes: 0 done, 1 failed or not configured.
 */
final readonly class PricesProbeSpaceshipCommand implements Command
{
    /** @param \Closure(): SpaceshipPriceCheck $check built lazily so keys are read only when it runs */
    public function __construct(private \Closure $check, private Console $console)
    {
    }

    public function name(): string
    {
        return 'prices:probe-spaceship';
    }

    public function execute(array $arguments): int
    {
        try {
            $run = ($this->check)()->run('cli');
        } catch (\InvalidArgumentException $e) {
            $this->console->error('Spaceship price check is not configured: ' . $e->getMessage());

            return 1;
        }
        if ($run->failure !== null) {
            $this->console->line('spaceship outcome=failed reason=' . $run->failure);

            return 1;
        }
        $this->console->line(sprintf('spaceship outcome=done asked=%d answered=%d priced=%d', $run->asked, $run->answered(), $run->priced()));

        return 0;
    }
}
