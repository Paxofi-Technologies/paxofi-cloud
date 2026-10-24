<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Registrar\Dynadot;

use PaxofiCloud\Domain\Pricing\CostCurrency;
use PaxofiCloud\Domain\Registrar\CostAmount;
use PaxofiCloud\Domain\Registrar\SkippedTld;
use PaxofiCloud\Domain\Registrar\TldCost;
use PaxofiCloud\Domain\Registrar\TldName;

/**
 * Turns one row of `data.tld_price_list` into a TldCost, or a skip with a
 * fixed reason (THREAT-MODEL-DOMAIN-PRICING.md DY-5, DY-6). The input comes
 * from ExactJson, so numbers are decimal text.
 *
 * The `all_years_*` arrays are cumulative totals: index 0 is the one-year
 * price, and only that is used. Restore is never in this feed, so it stays
 * empty (R-7). A first year cheaper than the renewal is marked `promo`.
 */
final class DynadotTldParser
{
    public static function parse(mixed $raw, CostCurrency $currency): TldCost|SkippedTld
    {
        if (!is_array($raw) || ($raw !== [] && array_is_list($raw))) {
            return new SkippedTld(null, 'not_object');
        }
        $name = is_string($raw['tld'] ?? null) ? $raw['tld'] : null;
        $skip = static fn (string $reason, ?string $detail = null): SkippedTld => new SkippedTld($name, $reason, $detail);
        try {
            $tld = TldName::fromFeed($name ?? '');
        } catch (\InvalidArgumentException) {
            return $skip('bad_name');
        }

        $minYears = self::years($raw['min_duration'] ?? '1');
        $maxYears = self::years($raw['max_duration'] ?? '10');
        if ($minYears === null || $maxYears === null || $minYears > min($maxYears, 10)) {
            return $skip('bad_period');
        }
        if ($minYears > 1) {
            return $skip('no_register_price', 'periods:' . $minYears);
        }

        $required = [];
        foreach (['register' => 'reg', 'renew' => 'renew'] as $type => $short) {
            $list = $raw['all_years_' . $type . '_price'] ?? null;
            if ($list === null || $list === []) {
                return $skip('no_' . $type . '_price');
            }
            if (!is_array($list) || !array_is_list($list)) {
                return $skip('bad_price', $short . ':type');
            }
            $amount = self::amount($list[0], $currency);
            if (is_string($amount)) {
                return $skip('bad_price', $short . ':' . $amount);
            }
            $required[$type] = $amount;
        }

        // DR-15: an unusable transfer price drops that price, not the extension.
        $transfer = self::amount($raw['transfer_price'] ?? null, $currency);

        $status = $required['register']->minor < $required['renew']->minor ? 'promo' : 'active';

        return new TldCost(
            $tld,
            $required['register'],
            $required['renew'],
            $transfer instanceof CostAmount ? $transfer : null,
            null,
            $minYears,
            min($maxYears, 10),
            $status,
        );
    }

    /** A cost, or the kind of problem: missing, type, zero, negative or format. */
    private static function amount(mixed $value, CostCurrency $currency): CostAmount|string
    {
        if ($value === null) {
            return 'missing';
        }
        if (!is_string($value)) {
            return 'type';
        }
        // Thousands separators ("1,234.00") are accepted and removed; nothing else is rewritten.
        if (preg_match('/^[0-9]{1,3}(,[0-9]{3})+(\.[0-9]+)?$/', $value) === 1) {
            $value = str_replace(',', '', $value);
        }
        if (str_starts_with($value, '-')) {
            return 'negative';
        }
        if (preg_match('/^0+(\.0+)?$/', $value) === 1) {
            return 'zero';
        }
        try {
            return CostAmount::fromDecimalText($currency, $value);
        } catch (\InvalidArgumentException) {
            return 'format';
        }
    }

    private static function years(mixed $value): ?int
    {
        return is_string($value) && preg_match('/^[1-9][0-9]?$/', $value) === 1 ? (int) $value : null;
    }
}
