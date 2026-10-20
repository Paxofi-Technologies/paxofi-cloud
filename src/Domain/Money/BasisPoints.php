<?php

declare(strict_types=1);

namespace PaxofiCloud\Domain\Money;

/**
 * A percentage as whole basis points: 7.5% is 750 (commerce threat model
 * CD-2). Bounded to 0–100%, which covers VAT rates and discounts.
 */
final readonly class BasisPoints
{
    public const int MAX = 10_000;

    /** @throws \InvalidArgumentException */
    public function __construct(public int $value)
    {
        if ($value < 0 || $value > self::MAX) {
            throw new \InvalidArgumentException('Basis points must be between 0 and 10000.');
        }
    }
}
