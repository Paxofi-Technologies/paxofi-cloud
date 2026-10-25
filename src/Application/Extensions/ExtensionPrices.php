<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Extensions;

use PaxofiCloud\Domain\Money\Currency;
use PaxofiCloud\Domain\Pricing\BelowMarginFloor;
use PaxofiCloud\Domain\Pricing\CostCurrency;
use PaxofiCloud\Domain\Pricing\Decimal;
use PaxofiCloud\Domain\Pricing\DomainPricingSettings;
use PaxofiCloud\Domain\Pricing\ExchangeRates;
use PaxofiCloud\Domain\Pricing\PriceDeriver;
use PaxofiCloud\Domain\Registrar\CostAmount;
use PaxofiCloud\Domain\Registrar\TldCost;

/**
 * One-year selling prices for an extension in USD and NGN
 * (THREAT-MODEL-DOMAIN-PRICING.md DX-1, DX-5). USD comes from the chosen
 * registrar's cost with markup, rounding up and the margin floor; NGN is the
 * USD price converted at the accepted rate and rounded up to the NGN step.
 * An action without a cost, or whose price would fall below the floor, is
 * not sold online (null), so the system fails closed.
 */
final readonly class ExtensionPrices
{
    public const array ACTIONS = ['register', 'renew', 'transfer', 'restore'];

    /**
     * @param array<string, ?int> $usd action => USD cents, null when not sold online
     * @param array<string, ?int> $ngn action => kobo, null when not sold online
     */
    private function __construct(public array $usd, public array $ngn)
    {
    }

    public static function for(TldCost $cost, DomainPricingSettings $settings, ExchangeRates $rates): self
    {
        $deriver = new PriceDeriver($settings->minimumMargin);
        $usd = [];
        $ngn = [];
        foreach (self::ACTIONS as $action) {
            $amount = self::cost($cost, $action);
            $usd[$action] = null;
            $ngn[$action] = null;
            if ($amount === null) {
                continue;
            }
            try {
                $price = $deriver->derive($amount->minor, $amount->currency, Currency::USD, $rates, $settings->markup, $settings->usdStep)->price->minorUnits;
            } catch (BelowMarginFloor) {
                continue;
            }
            $usd[$action] = $price;
            $ngn[$action] = $settings->ngnStep->roundUp(Decimal::ceilToInt($rates->convert($price, CostCurrency::USD, Currency::NGN)));
        }

        return new self($usd, $ngn);
    }

    private static function cost(TldCost $cost, string $action): ?CostAmount
    {
        return match ($action) {
            'register' => $cost->register,
            'renew' => $cost->renew,
            'transfer' => $cost->transfer,
            default => $cost->restore,
        };
    }
}
