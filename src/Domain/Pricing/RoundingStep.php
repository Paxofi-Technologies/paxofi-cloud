<?php

declare(strict_types=1);

namespace PaxofiCloud\Domain\Pricing;

/**
 * Selling prices are rounded UP to a whole number of steps, in minor units:
 * a step of 10000 kobo gives prices in whole ₦100s; 50 cents gives $x.00 or
 * $x.50 (CD-14). Rounding up never cuts into the margin.
 */
final readonly class RoundingStep
{
    public const int MAX = 10_000_000;

    /** @throws \InvalidArgumentException */
    public function __construct(public int $minorUnits)
    {
        if ($minorUnits < 1 || $minorUnits > self::MAX) {
            throw new \InvalidArgumentException('Rounding step must be between 1 and 10000000 minor units.');
        }
    }

    public function roundUp(int $minorUnits): int
    {
        $remainder = $minorUnits % $this->minorUnits;

        return $remainder === 0 ? $minorUnits : $minorUnits + ($this->minorUnits - $remainder);
    }
}
