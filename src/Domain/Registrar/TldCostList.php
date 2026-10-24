<?php

declare(strict_types=1);

namespace PaxofiCloud\Domain\Registrar;

/**
 * The result of one full price-list fetch: every accepted extension plus a
 * count of refused items per fixed reason (DR-7). Refused items never stop a
 * run; their counts feed the snapshot and the quarantine check (CD-13).
 */
final readonly class TldCostList
{
    /** @var array<string, TldCost> keyed by extension */
    public array $items;

    /**
     * @param list<TldCost> $items
     * @param array<string, int> $refused reason => count
     */
    public function __construct(array $items, public array $refused, public string $environment)
    {
        $byTld = [];
        foreach ($items as $item) {
            if (isset($byTld[$item->tld->value])) {
                throw new \InvalidArgumentException('An extension appears twice in one price list.');
            }
            $byTld[$item->tld->value] = $item;
        }
        ksort($byTld, SORT_STRING);
        $this->items = $byTld;
        if (preg_match('/^[a-z]{2,8}$/', $environment) !== 1) {
            throw new \InvalidArgumentException('Environment must be a short code.');
        }
    }

    public function refusedTotal(): int
    {
        return array_sum($this->refused);
    }
}
