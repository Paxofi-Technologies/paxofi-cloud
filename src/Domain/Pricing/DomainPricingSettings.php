<?php

declare(strict_types=1);

namespace PaxofiCloud\Domain\Pricing;

use PaxofiCloud\Domain\Money\BasisPoints;

/**
 * How domain selling prices are derived (THREAT-MODEL-DOMAIN-PRICING.md
 * DX-5, commerce CD-14): markup on the chosen registrar's cost, the minimum
 * margin no price may fall below, and the rounding steps per currency.
 * Entered by staff; never committed, because the repository is public.
 */
final readonly class DomainPricingSettings
{
    public function __construct(
        public Markup $markup,
        public BasisPoints $minimumMargin,
        public RoundingStep $usdStep,
        public RoundingStep $ngnStep,
    ) {
        if ($minimumMargin->value > $markup->basisPoints) {
            throw new \InvalidArgumentException('The minimum margin cannot be higher than the markup.');
        }
    }
}
