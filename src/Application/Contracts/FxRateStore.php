<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Contracts;

use PaxofiCloud\Application\Pricing\StoredRates;
use PaxofiCloud\Domain\Pricing\ExchangeRates;
use PaxofiCloud\Domain\Pricing\FxRateVerdict;

/** Append-only record of every rate set fetched, accepted or held (CD-16). */
interface FxRateStore
{
    public function latestAccepted(): ?StoredRates;

    /** @return string the new rate set's ID */
    public function record(ExchangeRates $rates, FxRateVerdict $verdict, \DateTimeImmutable $fetchedAt): string;

    /** @return list<StoredRates> newest first */
    public function recent(int $limit): array;
}
