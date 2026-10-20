<?php

declare(strict_types=1);

namespace PaxofiCloud\Domain\Pricing;

use PaxofiCloud\Domain\Money\BasisPoints;
use PaxofiCloud\Domain\Money\Currency;
use PaxofiCloud\Domain\Money\Money;
use PaxofiCloud\Domain\Money\MoneyOutOfRange;

/**
 * selling price = round_up(convert(cost) × (1 + markup)) (commerce threat
 * model CD-14, PRC-012). The converted cost is rounded up to a whole minor
 * unit before anything else, so every later comparison errs towards cost.
 * A price below converted cost × (1 + minimum margin) is refused.
 */
final readonly class PriceDeriver
{
    private const int SCALE = 12;

    public function __construct(public BasisPoints $minimumMargin)
    {
    }

    /** @throws BelowMarginFloor|MoneyOutOfRange|\InvalidArgumentException */
    public function derive(int $costMinor, CostCurrency $costCurrency, Currency $target, ExchangeRates $rates, Markup $markup, RoundingStep $step): DerivedPrice
    {
        if ($costMinor <= 0) {
            throw new \InvalidArgumentException('Cost must be positive.');
        }
        $converted = Decimal::ceilToInt($rates->convert($costMinor, $costCurrency, $target));
        $marked = Decimal::ceilToInt(self::applyPercentage((string) $converted, $markup->basisPoints));
        $price = $step->roundUp($marked);
        $floor = Decimal::ceilToInt(self::applyPercentage((string) $converted, $this->minimumMargin->value));
        if ($price < $floor) {
            throw new BelowMarginFloor('Derived price is below cost plus the minimum margin.');
        }

        return new DerivedPrice(new Money($price, $target), new Money($converted, $target));
    }

    /**
     * $amount × (1 + basisPoints / 10000) as a decimal string.
     *
     * @param numeric-string $amount
     */
    private static function applyPercentage(string $amount, int $basisPoints): string
    {
        return bcdiv(bcmul($amount, (string) (10_000 + $basisPoints), self::SCALE), '10000', self::SCALE);
    }
}
