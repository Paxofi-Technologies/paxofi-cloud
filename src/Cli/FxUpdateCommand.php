<?php

declare(strict_types=1);

namespace PaxofiCloud\Cli;

use Paxofi\Core\Contracts\Command;
use PaxofiCloud\Application\Pricing\FxRateUpdater;
use PaxofiCloud\Application\Pricing\FxUpdateOutcome;

/**
 * `bin/paxoficloud fx:update` — fetch today's exchange rates (PRC-013, CD-16).
 * Exit 0 accepted, 2 held for Finance, 1 source unavailable.
 */
final readonly class FxUpdateCommand implements Command
{
    /** @param \Closure(): FxRateUpdater $updater built lazily so other commands never need the network */
    public function __construct(private \Closure $updater, private Console $console)
    {
    }

    public function name(): string
    {
        return 'fx:update';
    }

    public function execute(array $arguments): int
    {
        $outcome = ($this->updater)()->update();
        $this->console->line('fx ' . $outcome->value);

        return match ($outcome) {
            FxUpdateOutcome::Accepted => 0,
            FxUpdateOutcome::Held => 2,
            FxUpdateOutcome::Unavailable => 1,
        };
    }
}
