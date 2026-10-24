<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Registrar\DomainNameApi;

use PaxofiCloud\Domain\Pricing\CostCurrency;
use PaxofiCloud\Domain\Registrar\CostAmount;
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
        'no_register_price', 'no_renew_price', 'bad_price', 'unsupported_currency', 'duplicate',
    ];

    /** @return TldCost|string a TldCost, or one of REASONS */
    public static function item(mixed $raw): TldCost|string
    {
        if (!is_array($raw) || ($raw !== [] && array_is_list($raw))) {
            return 'not_object';
        }
        try {
            $tld = TldName::fromFeed(is_string($raw['name'] ?? null) ? $raw['name'] : '');
        } catch (\InvalidArgumentException) {
            return 'bad_name';
        }
        $status = self::status($raw['status'] ?? 'unknown');
        if ($status === null) {
            return 'bad_status';
        }
        $minYears = self::years($raw['minRegistrationPeriod'] ?? '1');
        $maxYears = self::years($raw['maxRegistrationPeriod'] ?? '10');
        if ($minYears === null || $maxYears === null || $minYears > min($maxYears, 10)) {
            return 'bad_period';
        }

        $prices = $raw['prices'] ?? null;
        if (!is_array($prices) || !is_array($prices[0] ?? null)) {
            return 'no_prices';
        }
        $byType = $prices[0];
        $register = self::oneYear($byType['register'] ?? null);
        if (is_string($register)) {
            return $register;
        }
        if ($register === null) {
            return 'no_register_price';
        }
        $renew = self::oneYear($byType['renew'] ?? null);
        if (is_string($renew)) {
            return $renew;
        }
        if ($renew === null) {
            return 'no_renew_price';
        }
        $transfer = self::oneYear($byType['transfer'] ?? null);
        if (is_string($transfer)) {
            return $transfer;
        }
        $restore = self::oneYear($byType['restore'] ?? null);
        if (is_string($restore)) {
            return $restore;
        }

        return new TldCost($tld, $register, $renew, $transfer, $restore, $minYears, min($maxYears, 10), $status);
    }

    /** null = absent; string = refusal reason */
    private static function oneYear(mixed $value): CostAmount|string|null
    {
        if ($value === null) {
            return null;
        }
        if (!is_array($value)) {
            return 'bad_price';
        }
        $entries = $value !== [] && array_is_list($value) ? $value : [$value];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                return 'bad_price';
            }
            if (($entry['period'] ?? '1') !== '1') {
                continue;
            }
            $currency = CostCurrency::tryFrom(strtoupper(is_string($entry['currency'] ?? null) ? $entry['currency'] : ''));
            if ($currency === null) {
                return 'unsupported_currency';
            }
            $price = $entry['price'] ?? null;
            try {
                return CostAmount::fromDecimalText($currency, is_string($price) ? $price : '');
            } catch (\InvalidArgumentException) {
                return 'bad_price';
            }
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
