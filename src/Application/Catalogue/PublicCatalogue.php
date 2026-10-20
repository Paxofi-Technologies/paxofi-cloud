<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Catalogue;

use PaxofiCloud\Application\Contracts\CatalogueStore;
use PaxofiCloud\Application\Contracts\Clock;
use PaxofiCloud\Domain\Catalogue\BillingCycle;
use PaxofiCloud\Domain\Catalogue\DatedPrice;
use PaxofiCloud\Domain\Catalogue\OptionChoice;
use PaxofiCloud\Domain\Catalogue\OptionDefinition;
use PaxofiCloud\Domain\Catalogue\OptionKind;
use PaxofiCloud\Domain\Catalogue\PlanDefinition;
use PaxofiCloud\Domain\Money\Currency;

/**
 * The plans on sale, with NGN and USD prices, for the website and the portal
 * (CAT-006, PRC-006). Only ACTIVE, fully priced plans appear. The payload is
 * built field by field from an allow-list, so nothing internal can slip in
 * (commerce threat model CD-5, C-09).
 */
final readonly class PublicCatalogue
{
    public function __construct(private CatalogueStore $store, private Clock $clock)
    {
    }

    /** @return array{currencies: list<string>, products: list<array<string, mixed>>} */
    public function view(): array
    {
        $now = $this->clock->now();
        $products = [];
        foreach ($this->store->products() as $product) {
            $plans = [];
            foreach ($product->plans as $plan) {
                if ($plan->isForSale($now)) {
                    $plans[] = self::plan($plan, $now);
                }
            }
            if ($plans === []) {
                continue;
            }
            $products[] = [
                'slug' => $product->slug,
                'kind' => $product->kind->value,
                'name' => $product->name,
                'description' => $product->description,
                'plans' => $plans,
            ];
        }

        return ['currencies' => array_map(static fn (Currency $c): string => $c->value, Currency::cases()), 'products' => $products];
    }

    /** @return array<string, mixed> */
    private static function plan(PlanDefinition $plan, \DateTimeImmutable $now): array
    {
        return [
            'slug' => $plan->slug,
            'name' => $plan->name,
            'description' => $plan->description,
            'cycles' => array_map(static fn (BillingCycle $cycle): array => [
                'cycle' => $cycle->value,
                'months' => $cycle->months(),
                'prices' => self::prices($plan->prices, $cycle, $now),
            ], $plan->cycles),
            'options' => array_map(static fn (OptionDefinition $o): array => self::option($o, $plan->cycles, $now), $plan->options),
        ];
    }

    /**
     * @param list<BillingCycle> $cycles
     * @return array<string, mixed>
     */
    private static function option(OptionDefinition $option, array $cycles, \DateTimeImmutable $now): array
    {
        $view = [
            'code' => $option->code,
            'label' => $option->label,
            'type' => $option->kind->value,
            'required' => $option->required,
        ];
        if ($option->kind === OptionKind::Integer) {
            $view['min'] = $option->min;
            $view['max'] = $option->max;
        }
        if ($option->kind === OptionKind::Choice) {
            $view['choices'] = array_map(static fn (OptionChoice $choice): array => [
                'code' => $choice->code,
                'label' => $choice->label,
                'prices' => self::modifierPrices($choice->prices, $cycles, $now),
            ], $option->choices);
        } else {
            $view['unit_prices'] = self::modifierPrices($option->unitPrices, $cycles, $now);
        }

        return $view;
    }

    /**
     * @param list<DatedPrice> $prices
     * @param list<BillingCycle> $cycles
     * @return array<string, array<string, int>>
     */
    private static function modifierPrices(array $prices, array $cycles, \DateTimeImmutable $now): array
    {
        $view = [];
        foreach ($cycles as $cycle) {
            foreach (Currency::cases() as $currency) {
                $price = DatedPrice::current($prices, $cycle, $currency, $now);
                $view[$cycle->value][$currency->value] = $price === null ? 0 : $price->firstTerm->minorUnits;
            }
        }

        return $view;
    }

    /**
     * @param list<DatedPrice> $prices
     * @return array<string, array{first_term: int, renewal: int}>
     */
    private static function prices(array $prices, BillingCycle $cycle, \DateTimeImmutable $now): array
    {
        $view = [];
        foreach (Currency::cases() as $currency) {
            $price = DatedPrice::current($prices, $cycle, $currency, $now);
            if ($price !== null) {
                $view[$currency->value] = ['first_term' => $price->firstTerm->minorUnits, 'renewal' => $price->renewal->minorUnits];
            }
        }

        return $view;
    }
}
