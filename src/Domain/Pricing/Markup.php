<?php

declare(strict_types=1);

namespace PaxofiCloud\Domain\Pricing;

/**
 * A markup on cost in basis points: 2500 is +25% (CD-14). Unlike a tax rate a
 * markup may exceed 100%, so it is bounded at 1000% instead of 100%.
 */
final readonly class Markup
{
    public const int MAX = 100_000;

    /** @throws \InvalidArgumentException */
    public function __construct(public int $basisPoints)
    {
        if ($basisPoints < 0 || $basisPoints > self::MAX) {
            throw new \InvalidArgumentException('Markup must be between 0 and 100000 basis points.');
        }
    }
}
