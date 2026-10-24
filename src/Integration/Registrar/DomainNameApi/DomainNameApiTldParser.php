<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Registrar\DomainNameApi;

use PaxofiCloud\Domain\Pricing\CostCurrency;
use PaxofiCloud\Domain\Registrar\CostAmount;
use PaxofiCloud\Domain\Registrar\SkippedTld;
use PaxofiCloud\Domain\Registrar\TldCost;
use PaxofiCloud\Domain\Registrar\TldName;

/**
 * Turns one item of `GET products/tlds` into a TldCost, or a fixed refusal
 * reason (DR-7). The input comes from ExactJson, so every number is already
 * decimal text. Field names follow the vendor client v4.0.3 (§2 of the threat
 * model): name, status, minRegistrationPeriod, maxRegistrationPeriod and
 * prices[0].{register,renew,transfer,restore}, each either one
 * {price, currency, period} object or a list of them per period.
 */
final class DomainNameApiTldParser
{
    public const array REASONS = [
        'not_object', 'bad_name', 'bad_status', 'bad_period', 'no_prices',
        'no_register_price', 'no_renew_price', 'bad_price', 'unsupported_currency', 'mixed_currency', 'duplicate',
    ];

    /** @return TldCost|string a TldCost, or one of REASONS */
    public static function item(mixed $raw): TldCost|string
    {
        $result = self::parse($raw);

        return $result instanceof SkippedTld ? $result->reason : $result;
    }

    /**
     * Like item(), but a refusal keeps the name and a short detail (DR-14):
     * the currency code for unsupported_currency, or `periods:N` (the
     * shortest period offered) when there is no one-year price.
     */
    public static function parse(mixed $raw): TldCost|SkippedTld
    {
        if (!is_array($raw) || ($raw !== [] && array_is_list($raw))) {
            return new SkippedTld(null, 'not_object');
        }
        $name = is_string($raw['name'] ?? null) ? $raw['name'] : null;
        $skip = static fn (string $reason, ?string $detail = null): SkippedTld => new SkippedTld($name, $reason, $detail);
        try {
            $tld = TldName::fromFeed($name ?? '');
        } catch (\InvalidArgumentException) {
            return $skip('bad_name');
        }
        $status = self::status($raw['status'] ?? 'unknown');
        if ($status === null) {
            return $skip('bad_status');
        }
        $minYears = self::years($raw['minRegistrationPeriod'] ?? '1');
        $maxYears = self::years($raw['maxRegistrationPeriod'] ?? '10');
        if ($minYears === null || $maxYears === null || $minYears > min($maxYears, 10)) {
            return $skip('bad_period');
        }

        $prices = $raw['prices'] ?? null;
        if (!is_array($prices) || !is_array($prices[0] ?? null)) {
            return $skip('no_prices');
        }
        $byType = $prices[0];
        $amounts = [];
        foreach (['register' => true, 'renew' => true, 'transfer' => false, 'restore' => false] as $type => $required) {
            $detail = null;
            $amount = self::oneYear($byType[$type] ?? null, $detail);
            if (is_string($amount)) {
                return $skip($amount, $detail);
            }
            if ($amount === null && $required) {
                return $skip('no_' . $type . '_price', $detail);
            }
            $amounts[$type] = $amount;
        }
        $register = $amounts['register'];
        $renew = $amounts['renew'];
        \assert($register instanceof CostAmount && $renew instanceof CostAmount);

        foreach ([$renew, $amounts['transfer'], $amounts['restore']] as $other) {
            if ($other !== null && $other->currency !== $register->currency) {
                return $skip('mixed_currency');
            }
        }

        return new TldCost($tld, $register, $renew, $amounts['transfer'], $amounts['restore'], $minYears, min($maxYears, 10), $status);
    }

    /**
     * null = absent; string = refusal reason. $detail receives the currency
     * code for an unsupported currency, or `periods:N` when the only prices
     * are for more than one year.
     */
    private static function oneYear(mixed $value, ?string &$detail): CostAmount|string|null
    {
        if ($value === null) {
            return null;
        }
        if (!is_array($value)) {
            return 'bad_price';
        }
        $entries = $value !== [] && array_is_list($value) ? $value : [$value];
        $shortest = null;
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                return 'bad_price';
            }
            $period = $entry['period'] ?? '1';
            if ($period !== '1') {
                if (is_string($period) && preg_match('/^[0-9]{1,2}$/', $period) === 1 && ($shortest === null || (int) $period < $shortest)) {
                    $shortest = (int) $period;
                }
                continue;
            }
            $code = strtoupper(is_string($entry['currency'] ?? null) ? $entry['currency'] : '');
            $currency = CostCurrency::tryFrom($code);
            if ($currency === null) {
                $detail = $code;

                return 'unsupported_currency';
            }
            $price = $entry['price'] ?? null;
            try {
                return CostAmount::fromDecimalText($currency, is_string($price) ? $price : '');
            } catch (\InvalidArgumentException) {
                return 'bad_price';
            }
        }
        if ($shortest !== null) {
            $detail = 'periods:' . $shortest;
        }

        return null;
    }

    private static function years(mixed $value): ?int
    {
        if (!is_string($value) || preg_match('/^[0-9]{1,2}$/', $value) !== 1 || (int) $value < 1) {
            return null;
        }

        return (int) $value;
    }

    private static function status(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        if (preg_match('/^[0-9]{1,6}$/', $value) === 1) {
            return 'code_' . $value;
        }
        $normalised = strtolower($value);

        return preg_match('/^[a-z][a-z0-9_]{0,31}$/', $normalised) === 1 ? $normalised : null;
    }
}
