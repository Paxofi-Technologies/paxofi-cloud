<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Pricing;

use PaxofiCloud\Domain\Pricing\ExchangeRates;
use PaxofiCloud\Domain\Pricing\FxRateVerdict;

/** A rate set as stored, with its verdict and when it was fetched. */
final readonly class StoredRates
{
    public function __construct(
        public string $id,
        public ExchangeRates $rates,
        public FxRateVerdict $verdict,
        public \DateTimeImmutable $fetchedAt,
    ) {
    }
}
