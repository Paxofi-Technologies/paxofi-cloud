<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Support;

use PaxofiCloud\Application\Contracts\FxRateStore;
use PaxofiCloud\Application\Pricing\StoredRates;
use PaxofiCloud\Domain\Pricing\ExchangeRates;
use PaxofiCloud\Domain\Pricing\FxRateVerdict;

final class InMemoryFxRateStore implements FxRateStore
{
    /** @var list<StoredRates> oldest first */
    public array $sets = [];

    public function latestAccepted(): ?StoredRates
    {
        foreach (array_reverse($this->sets) as $set) {
            if ($set->verdict === FxRateVerdict::Accepted) {
                return $set;
            }
        }

        return null;
    }

    public function record(ExchangeRates $rates, FxRateVerdict $verdict, \DateTimeImmutable $fetchedAt): string
    {
        $id = 'set-' . (count($this->sets) + 1);
        $this->sets[] = new StoredRates($id, $rates, $verdict, $fetchedAt);

        return $id;
    }

    public function recent(int $limit): array
    {
        return array_slice(array_reverse($this->sets), 0, $limit);
    }
}
