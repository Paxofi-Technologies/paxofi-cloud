<?php

declare(strict_types=1);

namespace PaxofiCloud\Domain\Pricing;

use PaxofiCloud\Domain\Money\BasisPoints;

/**
 * Safety rules for the free exchange-rate source (commerce threat model
 * CD-16, C-27, PRC-013): a move of more than 5% against the last accepted
 * rate is held for Finance; repricing follows only a move of more than 3%;
 * after 72 hours without an accepted rate automatic repricing pauses.
 */
final readonly class FxRatePolicy
{
    public function __construct(
        public BasisPoints $holdAbove,
        public BasisPoints $repriceAbove,
        public int $staleAfterSeconds,
    ) {
        if ($staleAfterSeconds < 3_600) {
            throw new \InvalidArgumentException('Staleness limit must be at least one hour.');
        }
    }

    public static function defaults(): self
    {
        return new self(new BasisPoints(500), new BasisPoints(300), 72 * 3_600);
    }

    public function verdict(?ExchangeRates $lastAccepted, ExchangeRates $candidate): FxRateVerdict
    {
        if ($lastAccepted === null) {
            // The very first rates have nothing to compare with; Finance sees them in the console.
            return FxRateVerdict::Accepted;
        }
        foreach (CostCurrency::cases() as $currency) {
            if ($lastAccepted->rate($currency)->moveTo($candidate->rate($currency)) > $this->holdAbove->value) {
                return FxRateVerdict::Held;
            }
        }

        return FxRateVerdict::Accepted;
    }

    /** Whether converted prices derived at $pricedWith should be recomputed now (hysteresis). */
    public function warrantsRepricing(ExchangeRates $pricedWith, ExchangeRates $now): bool
    {
        foreach (CostCurrency::cases() as $currency) {
            if ($pricedWith->rate($currency)->moveTo($now->rate($currency)) > $this->repriceAbove->value) {
                return true;
            }
        }

        return false;
    }

    /** True when no rate has been accepted yet or the last one is older than the limit. */
    public function isStale(?\DateTimeImmutable $lastAcceptedAt, \DateTimeImmutable $now): bool
    {
        return $lastAcceptedAt === null || $now->getTimestamp() - $lastAcceptedAt->getTimestamp() > $this->staleAfterSeconds;
    }
}
