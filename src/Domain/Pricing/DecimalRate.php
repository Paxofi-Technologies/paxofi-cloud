<?php

declare(strict_types=1);

namespace PaxofiCloud\Domain\Pricing;

/**
 * A positive exchange rate kept as a decimal string, never a float
 * (commerce threat model CD-14, C-31): "1332.819531" naira per US dollar.
 * At most 9 integer and 12 fractional digits; leading zeros are refused so
 * one rate has one spelling.
 */
final readonly class DecimalRate
{
    private const string PATTERN = '/^(0|[1-9][0-9]{0,8})(\.[0-9]{1,12})?$/';

    /** @var numeric-string */
    public string $value;

    /** @throws \InvalidArgumentException */
    public function __construct(string $value)
    {
        if (preg_match(self::PATTERN, $value) !== 1 || !is_numeric($value) || bccomp($value, '0', 12) <= 0) {
            throw new \InvalidArgumentException('Exchange rate must be a positive decimal with at most 9 integer and 12 fractional digits.');
        }
        $this->value = $value;
    }

    /**
     * How far $other is from this rate, in whole basis points of this rate,
     * rounded up so a move just over a limit is never reported as within it.
     */
    public function moveTo(self $other): int
    {
        $difference = bccomp($other->value, $this->value, 12) >= 0
            ? bcsub($other->value, $this->value, 12)
            : bcsub($this->value, $other->value, 12);
        $basisPoints = bcdiv(bcmul($difference, '10000', 12), $this->value, 12);

        return Decimal::ceilToInt($basisPoints);
    }
}
