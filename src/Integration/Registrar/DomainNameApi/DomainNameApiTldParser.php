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
 *
 * register and renew are required. An unreadable transfer or restore price
 * drops that one price, not the extension (DR-15). Every refusal carries a
 * short detail naming the failing field and how it failed (DR-14).
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
        $minRaw = $raw['minRegistrationPeriod'] ?? '1';
        $maxRaw = $raw['maxRegistrationPeriod'] ?? '10';
        $minYears = self::years($minRaw);
        $maxYears = self::years($maxRaw);
        if ($minYears === null || $maxYears === null || $minYears > min($maxYears, 10)) {
            return $skip('bad_period', 'min:' . self::periodText($minRaw) . '_max:' . self::periodText($maxRaw));
        }

        $prices = $raw['prices'] ?? null;
        if (!is_array($prices) || !is_array($prices[0] ?? null)) {
            return $skip('no_prices');
        }
        $byType = $prices[0];
        $required = [];
        foreach (['register' => 'reg', 'renew' => 'renew'] as $type => $short) {
            $detail = null;
            $amount = self::oneYear($byType[$type] ?? null, $detail);
            if (is_string($amount)) {
                return $skip($amount, $amount === 'bad_price' ? $short . ':' . $detail : $detail);
            }
            if ($amount === null) {
                return $skip('no_' . $type . '_price', $detail);
            }
            $required[$type] = $amount;
        }
        [$register, $renew] = [$required['register'], $required['renew']];
        if ($renew->currency !== $register->currency) {
            return $skip('mixed_currency');
        }

        // DR-15: an optional price that cannot be used is dropped on its own.
        $optional = [];
        foreach (['transfer', 'restore'] as $type) {
            $detail = null;
            $amount = self::oneYear($byType[$type] ?? null, $detail);
            $optional[$type] = $amount instanceof CostAmount && $amount->currency === $register->currency ? $amount : null;
        }

        return new TldCost($tld, $register, $renew, $optional['transfer'], $optional['restore'], $minYears, min($maxYears, 10), $status);
    }

    /**
     * null = absent; string = refusal reason. $detail receives the currency
     * code for an unsupported currency (or `currency:<kind>` when there is no
     * usable code), the kind of an unreadable price (`zero`, `negative`, …),
     * or `periods:N` when the only prices are for more than one year.
     */
    private static function oneYear(mixed $value, ?string &$detail): CostAmount|string|null
    {
        if ($value === null) {
            return null;
        }
        if (!is_array($value)) {
            $detail = 'type';

            return 'bad_price';
        }
        $entries = $value !== [] && array_is_list($value) ? $value : [$value];
        $shortest = null;
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                $detail = 'entry';

                return 'bad_price';
            }
            $period = $entry['period'] ?? '1';
            if ($period !== '1') {
                if (is_string($period) && preg_match('/^[0-9]{1,2}$/', $period) === 1 && ($shortest === null || (int) $period < $shortest)) {
                    $shortest = (int) $period;
                }
                continue;
            }
            $rawCode = $entry['currency'] ?? null;
            $code = strtoupper(is_string($rawCode) ? $rawCode : '');
            $currency = CostCurrency::tryFrom($code);
            if ($currency === null) {
                $detail = match (true) {
                    $rawCode === null => 'currency:missing',
                    !is_string($rawCode) => 'currency:type',
                    preg_match('/^[A-Z]{3}$/', $code) === 1 => $code,
                    default => 'currency:format',
                };

                return 'unsupported_currency';
            }
            $price = $entry['price'] ?? null;
            try {
                return CostAmount::fromDecimalText($currency, is_string($price) ? $price : '');
            } catch (\InvalidArgumentException) {
                $detail = self::priceProblem($price);

                return 'bad_price';
            }
        }
        if ($shortest !== null) {
            $detail = 'periods:' . $shortest;
        }

        return null;
    }

    /** A short, fixed word for why a price could not be read (DR-14 detail). */
    private static function priceProblem(mixed $price): string
    {
        return match (true) {
            $price === null => 'missing',
            !is_string($price) => 'type',
            preg_match('/^-/', $price) === 1 => 'negative',
            preg_match('/^0+(\.0+)?$/', $price) === 1 => 'zero',
            default => 'format',
        };
    }

    /** A period value for a detail: one or two digits as is, anything else `x`. */
    private static function periodText(mixed $value): string
    {
        return is_string($value) && preg_match('/^[0-9]{1,2}$/', $value) === 1 ? $value : 'x';
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
