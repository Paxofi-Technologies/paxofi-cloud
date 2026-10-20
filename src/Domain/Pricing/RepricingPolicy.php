<?php

declare(strict_types=1);

namespace PaxofiCloud\Domain\Pricing;

use PaxofiCloud\Domain\Money\BasisPoints;
use PaxofiCloud\Domain\Money\Money;

/**
 * CEO decision 20 Oct 2026 (CD-15, PRC-012): a change of at most the
 * approval threshold (default 10%) goes live automatically from the next
 * day; anything larger, up or down, waits for staff approval; a cost above
 * the current price stops sales immediately.
 */
final readonly class RepricingPolicy
{
    public function __construct(public BasisPoints $approvalThreshold)
    {
    }

    public static function ceoDefault(): self
    {
        return new self(new BasisPoints(1_000));
    }

    /** @throws \PaxofiCloud\Domain\Money\CurrencyMismatch */
    public function decide(Money $current, DerivedPrice $derived): RepricingDecision
    {
        if ($derived->convertedCost->compare($current) >= 0) {
            return RepricingDecision::StopSelling;
        }
        if ($derived->price->equals($current)) {
            return RepricingDecision::NoChange;
        }

        return self::changeInBasisPoints($current->minorUnits, $derived->price->minorUnits) <= $this->approvalThreshold->value
            ? RepricingDecision::ApplyNextDay
            : RepricingDecision::NeedsApproval;
    }

    /** |new − current| / current in basis points, rounded up. */
    private static function changeInBasisPoints(int $current, int $new): int
    {
        if ($current <= 0) {
            return PHP_INT_MAX;
        }
        $difference = abs($new - $current);

        return intdiv($difference * 10_000 + $current - 1, $current);
    }
}
