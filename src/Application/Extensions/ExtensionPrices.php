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
 *
 * Staff price overrides (DX-14) replace the markup price for register, renew
 * and transfer in USD and/or NGN. An override is used only while it is at or
 * above the floor for today's cost and rate; otherwise the markup price is
 * charged and the override is reported in $ignored, so a stale override can
 * never sell at a loss. A USD override without an NGN override converts as
 * usual.
 */
final readonly class ExtensionPrices
{
    public const array ACTIONS = ['register', 'renew', 'transfer', 'restore'];

    /**
     * @param array<string, ?int> $usd action => USD cents, null when not sold online
     * @param array<string, ?int> $ngn action => kobo, null when not sold online
     */
    /**
     * @param array<string, ?int> $usd
     * @param array<string, ?int> $ngn
     * @param list<string> $ignored overrides not charged (below today's floor, or the action is not sold online), as "usd.register"
     */
    private function __construct(public array $usd, public array $ngn, public array $ignored = [])
    {
    }

    public static function for(TldCost $cost, DomainPricingSettings $settings, ExchangeRates $rates, ?ExtensionSettings $own = null): self
    {
        $ignored = [];
        $deriver = new PriceDeriver($settings->minimumMargin);
        $usd = [];
        $ngn = [];
        foreach (self::ACTIONS as $action) {
            $amount = self::cost($cost, $action);
            $usd[$action] = null;
            $ngn[$action] = null;
            try {
                if ($amount === null) {
                    throw new BelowMarginFloor('No cost for this action.');
                }
                $price = $deriver->derive($amount->minor, $amount->currency, Currency::USD, $rates, $settings->markup, $settings->usdStep)->price->minorUnits;
            } catch (BelowMarginFloor) {
                // Not sold online; a staff price cannot make it sellable.
                foreach (['usd', 'ngn'] as $currency) {
                    if ($own?->override($currency, $action) !== null) {
                        $ignored[] = $currency . '.' . $action;
                    }
                }
                continue;
            }
            $usdOverride = $own?->override('usd', $action);
            if ($usdOverride !== null) {
                if ($usdOverride >= $deriver->minimumPrice($amount->minor, $amount->currency, Currency::USD, $rates)) {
                    $price = $usdOverride;
                } else {
                    $ignored[] = 'usd.' . $action;
                }
            }
            $usd[$action] = $price;
            $ngn[$action] = $settings->ngnStep->roundUp(Decimal::ceilToInt($rates->convert($price, CostCurrency::USD, Currency::NGN)));
            $ngnOverride = $own?->override('ngn', $action);
            if ($ngnOverride !== null) {
                if ($ngnOverride >= $deriver->minimumPrice($amount->minor, $amount->currency, Currency::NGN, $rates)) {
                    $ngn[$action] = $ngnOverride;
                } else {
                    $ignored[] = 'ngn.' . $action;
                }
            }
        }

        return new self($usd, $ngn, $ignored);
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
