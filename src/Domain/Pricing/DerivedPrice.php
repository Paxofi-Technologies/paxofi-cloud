<?php

declare(strict_types=1);

namespace PaxofiCloud\Domain\Pricing;

use PaxofiCloud\Domain\Money\Money;

/**
 * A selling price derived from a provider cost (CD-14), with the converted
 * cost it came from so the margin floor and the "cost above price" rule
 * (CD-15) can be checked against it.
 */
final readonly class DerivedPrice
{
    public function __construct(public Money $price, public Money $convertedCost)
    {
    }
}
