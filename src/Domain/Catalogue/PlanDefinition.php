<?php

declare(strict_types=1);

namespace PaxofiCloud\Domain\Catalogue;

use PaxofiCloud\Domain\Money\Currency;

/**
 * A plan as the catalogue sells it: state, cycles, dated prices and typed
 * options (CAT-002, CAT-004, PRC-002, PRC-005, PRC-006). Holds no provider
 * mapping or cost (CAT-003, PRC-004; threat model CD-5).
 */
final readonly class PlanDefinition
{
    /**
     * @param list<BillingCycle> $cycles
     * @param list<DatedPrice> $prices
     * @param list<OptionDefinition> $options
     */
    public function __construct(
        public string $id,
        public string $slug,
        public ProductKind $kind,
        public string $name,
        public string $description,
        public PlanState $state,
        public array $cycles,
        public array $prices,
        public array $options,
    ) {
        foreach ($cycles as $cycle) {
            if (!in_array($cycle, $kind->allowedCycles(), true)) {
                throw new CatalogueRuleViolation('cycle_not_allowed', $cycle->value);
            }
        }
    }

    public function offers(BillingCycle $cycle): bool
    {
        return in_array($cycle, $this->cycles, true);
    }

    public function price(BillingCycle $cycle, Currency $currency, \DateTimeImmutable $at): ?DatedPrice
    {
        return $this->offers($cycle) ? DatedPrice::current($this->prices, $cycle, $currency, $at) : null;
    }

    /**
     * True when every cycle has a price in force in both NGN and USD, and every
     * priced option is priced the same way (PRC-002). A plan that is not
     * complete cannot become ACTIVE and is not shown for sale.
     */
    public function isFullyPriced(\DateTimeImmutable $at): bool
    {
        if ($this->cycles === []) {
            return false;
        }
        foreach ($this->cycles as $cycle) {
            foreach (Currency::cases() as $currency) {
                if ($this->price($cycle, $currency, $at) === null) {
                    return false;
                }
                foreach ($this->options as $option) {
                    $priceLists = $option->kind === OptionKind::Choice
                        ? array_map(static fn (OptionChoice $c): array => $c->prices, $option->choices)
                        : [$option->unitPrices];
                    foreach ($priceLists as $prices) {
                        if ($prices !== [] && DatedPrice::current($prices, $cycle, $currency, $at) === null) {
                            return false;
                        }
                    }
                }
            }
        }

        return true;
    }

    /** For sale to new orders right now (threat model C-07). */
    public function isForSale(\DateTimeImmutable $at): bool
    {
        return $this->state->acceptsNewOrders() && $this->isFullyPriced($at);
    }

    /** @throws CatalogueRuleViolation */
    public function assertCanMoveTo(PlanState $to, \DateTimeImmutable $at): void
    {
        if (!$this->state->canMoveTo($to)) {
            throw new CatalogueRuleViolation('transition_not_allowed', 'state');
        }
        if ($to === PlanState::Active && !$this->isFullyPriced($at)) {
            throw new CatalogueRuleViolation('prices_incomplete', 'state');
        }
    }

    /**
     * Validates requested option values against the plan (CAT-004). Unknown
     * options are refused; the error names the option.
     *
     * @param array<array-key, mixed> $requested
     * @return array<string, string|int|bool>
     * @throws CatalogueRuleViolation
     */
    public function validateOptions(array $requested): array
    {
        $known = [];
        foreach ($this->options as $option) {
            $known[$option->code] = $option;
        }
        foreach (array_keys($requested) as $code) {
            if (!is_string($code) || !isset($known[$code])) {
                throw new CatalogueRuleViolation('unknown_option', is_string($code) && preg_match('/^[a-z][a-z0-9_]{0,31}$/', $code) === 1 ? $code : 'option');
            }
        }
        $values = [];
        foreach ($known as $code => $option) {
            $value = $option->validate($requested[$code] ?? null);
            if ($value !== null) {
                $values[$code] = $value;
            }
        }

        return $values;
    }
}
