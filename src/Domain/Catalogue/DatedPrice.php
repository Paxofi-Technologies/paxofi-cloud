<?php

declare(strict_types=1);

namespace PaxofiCloud\Domain\Catalogue;

use PaxofiCloud\Domain\Money\Currency;
use PaxofiCloud\Domain\Money\Money;

/**
 * A price row that applies from `effectiveFrom` until a later row for the same
 * cycle and currency takes over (PRC-005). For plans, `renewal` may differ
 * from `firstTerm` (PRC-006); for option modifiers both are the same amount.
 */
final readonly class DatedPrice
{
    public function __construct(
        public BillingCycle $cycle,
        public Money $firstTerm,
        public Money $renewal,
        public \DateTimeImmutable $effectiveFrom,
    ) {
        if ($firstTerm->currency !== $renewal->currency) {
            throw new CatalogueRuleViolation('currency_mismatch', 'price');
        }
        if ($firstTerm->isNegative() || $renewal->isNegative()) {
            throw new CatalogueRuleViolation('negative_price', 'price');
        }
    }

    /**
     * The row in force at `$at` for the cycle and currency: the latest one
     * whose effective date is not after `$at`.
     *
     * @param list<self> $prices
     */
    public static function current(array $prices, BillingCycle $cycle, Currency $currency, \DateTimeImmutable $at): ?self
    {
        $best = null;
        foreach ($prices as $price) {
            if ($price->cycle !== $cycle || $price->firstTerm->currency !== $currency || $price->effectiveFrom > $at) {
                continue;
            }
            if ($best === null || $price->effectiveFrom > $best->effectiveFrom) {
                $best = $price;
            }
        }

        return $best;
    }
}
