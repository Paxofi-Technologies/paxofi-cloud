<?php

declare(strict_types=1);

namespace PaxofiCloud\Domain\Registrar;

/**
 * The result of one full price-list fetch: every accepted extension plus a
 * count of refused items per fixed reason (DR-7). Refused items never stop a
 * run; their counts feed the snapshot and the quarantine check (CD-13), and
 * the first MAX_SKIPPED of them are kept by name so staff can see what is
 * missing (DR-14).
 */
final readonly class TldCostList
{
    public const int MAX_SKIPPED = 1000;

    /** @var list<SkippedTld> */
    public array $skipped;

    /** @var array<string, TldCost> keyed by extension */
    public array $items;

    /**
     * @param list<TldCost> $items
     * @param array<string, int> $refused reason => count
     * @param list<SkippedTld> $skipped
     */
    public function __construct(array $items, public array $refused, public string $environment, array $skipped = [])
    {
        $this->skipped = array_slice($skipped, 0, self::MAX_SKIPPED);
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
