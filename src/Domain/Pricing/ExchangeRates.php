<?php

declare(strict_types=1);

namespace PaxofiCloud\Domain\Pricing;

use PaxofiCloud\Domain\Money\Currency;

/**
 * One accepted set of rates against the US dollar (CD-16): how many units of
 * each currency one dollar buys. Conversion goes through the dollar and is
 * done in bcmath decimals, so there is no float and no integer overflow.
 */
final readonly class ExchangeRates
{
    private const int SCALE = 12;

    /** @var array<string, DecimalRate> currency code => units per 1 USD */
    private array $perUsd;

    /**
     * @param array<string, DecimalRate> $perUsd must cover every CostCurrency; USD must be exactly 1
     * @throws \InvalidArgumentException
     */
    public function __construct(array $perUsd, public string $source, public \DateTimeImmutable $publishedAt)
    {
        foreach (CostCurrency::cases() as $currency) {
            if (!isset($perUsd[$currency->value])) {
                throw new \InvalidArgumentException('Exchange rates must cover ' . $currency->value . '.');
            }
        }
        if (bccomp($perUsd['USD']->value, '1', self::SCALE) !== 0) {
            throw new \InvalidArgumentException('The US dollar rate against itself must be 1.');
        }
        $this->perUsd = $perUsd;
    }

    public function rate(CostCurrency $currency): DecimalRate
    {
        return $this->perUsd[$currency->value];
    }

    /**
     * $minorUnits of $from expressed in minor units of $to, as an unrounded
     * decimal string. All supported currencies have two minor-unit digits, so
     * minor units convert at the same rate as major units.
     *
     * @throws \InvalidArgumentException for a negative amount
     */
    public function convert(int $minorUnits, CostCurrency $from, Currency $to): string
    {
        if ($minorUnits < 0) {
            throw new \InvalidArgumentException('Only non-negative amounts are converted.');
        }
        $target = CostCurrency::fromSelling($to);
        if ($target === $from) {
            return (string) $minorUnits;
        }
        // Multiply before dividing so the only truncation is the final one, at 12 decimal places.
        return bcdiv(bcmul((string) $minorUnits, $this->perUsd[$target->value]->value, self::SCALE), $this->perUsd[$from->value]->value, self::SCALE);
    }
}
