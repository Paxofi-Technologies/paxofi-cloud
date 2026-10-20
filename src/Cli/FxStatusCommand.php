<?php

declare(strict_types=1);

namespace PaxofiCloud\Cli;

use Paxofi\Core\Contracts\Command;
use PaxofiCloud\Application\Contracts\FxRateStore;
use PaxofiCloud\Domain\Pricing\CostCurrency;

/** `bin/paxoficloud fx:status` — the latest rate sets and their verdicts. */
final readonly class FxStatusCommand implements Command
{
    private const int SETS = 5;

    public function __construct(private FxRateStore $store, private Console $console)
    {
    }

    public function name(): string
    {
        return 'fx:status';
    }

    public function execute(array $arguments): int
    {
        $sets = $this->store->recent(self::SETS);
        if ($sets === []) {
            $this->console->line('no exchange rates recorded yet');

            return 0;
        }
        foreach ($sets as $set) {
            $rates = [];
            foreach (CostCurrency::cases() as $currency) {
                $rates[] = $currency->value . '=' . $set->rates->rate($currency)->value;
            }
            $this->console->line(sprintf(
                '%s %s source=%s published=%s %s',
                $set->fetchedAt->format('Y-m-d\TH:i:s\Z'),
                $set->verdict->value,
                $set->rates->source,
                $set->rates->publishedAt->format('Y-m-d\TH:i:s\Z'),
                implode(' ', $rates),
            ));
        }

        return 0;
    }
}
