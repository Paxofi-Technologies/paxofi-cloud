<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Registrar\Namecheap;

use PaxofiCloud\Domain\Pricing\CostCurrency;
use PaxofiCloud\Domain\Registrar\CostAmount;

/**
 * One `<Price>` tier of a Namecheap price sheet: a term in years and either
 * its cost or why it could not be read. The raw attribute names are kept for
 * the field inventory (DR-16); their values are only used for kinds there.
 */
final readonly class NamecheapTier
{
    private const array ATTRIBUTES = [
        'Duration', 'DurationType', 'Price', 'PricingType', 'AdditionalCost', 'RegularPrice', 'RegularPriceType',
        'RegularAdditionalCost', 'YourPrice', 'YourPriceType', 'YourAdditonalCost', 'YourAdditionalCost',
        'PromotionPrice', 'Currency',
    ];

    /** @param array<string, string> $attributes */
    private function __construct(
        public int $years,
        public CostAmount|string $cost,
        public bool $promotion,
        public ?string $currencyDetail,
        public array $attributes,
    ) {
    }

    /** Reads the current `<Price>` element; null for terms that are not 1-10 whole years. */
    public static function read(\XMLReader $reader): ?self
    {
        $attributes = [];
        foreach (self::ATTRIBUTES as $name) {
            $value = $reader->getAttribute($name);
            if ($value !== null) {
                $attributes[$name] = $value;
            }
        }
        $duration = $attributes['Duration'] ?? '';
        if (strtoupper($attributes['DurationType'] ?? '') !== 'YEAR' || preg_match('/^[0-9]{1,2}$/', $duration) !== 1
            || (int) $duration < 1 || (int) $duration > 10) {
            return null;
        }
        $years = (int) $duration;
        $promotionPrice = $reader->getAttribute('PromotionPrice') ?? '';
        $promotion = preg_match('/^(0|[1-9][0-9]{0,8})(\.[0-9]{1,8})?$/', $promotionPrice) === 1
            && preg_match('/^0+(\.0+)?$/', $promotionPrice) !== 1;

        $rawCode = $attributes['Currency'] ?? null;
        $code = strtoupper($rawCode ?? '');
        $currency = CostCurrency::tryFrom($code);
        if ($currency === null) {
            $detail = match (true) {
                $rawCode === null || $rawCode === '' => 'currency:missing',
                preg_match('/^[A-Z]{3}$/', $code) === 1 => $code,
                default => 'currency:format',
            };

            return new self($years, 'unsupported_currency', $promotion, $detail, $attributes);
        }
        $additional = null;
        foreach (['AdditionalCost', 'YourAdditonalCost', 'YourAdditionalCost'] as $name) {
            $candidate = $attributes[$name] ?? null;
            if ($candidate !== null && ($additional === null || self::greater($candidate, $additional))) {
                $additional = $candidate;
            }
        }
        $cost = NamecheapPriceSheet::tierCost(
            $currency,
            $attributes['Price'] ?? null,
            $attributes['YourPrice'] ?? null,
            $attributes['RegularPrice'] ?? null,
            $additional,
        );

        return new self($years, $cost, $promotion, null, $attributes);
    }

    /** Compares two additional-cost texts; unreadable text never wins. */
    private static function greater(string $a, string $b): bool
    {
        try {
            $x = CostAmount::fromDecimalText(CostCurrency::USD, $a)->minor;
        } catch (\InvalidArgumentException) {
            return false;
        }
        try {
            return $x > CostAmount::fromDecimalText(CostCurrency::USD, $b)->minor;
        } catch (\InvalidArgumentException) {
            return true;
        }
    }
}
