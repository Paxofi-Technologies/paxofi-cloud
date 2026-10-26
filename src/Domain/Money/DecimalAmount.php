<?php

declare(strict_types=1);

namespace PaxofiCloud\Domain\Money;

/**
 * Converts between minor units and the decimal text some gateways use for
 * amounts (Flutterwave sends and expects major units, e.g. "17737.50"). Pure
 * string arithmetic: no float ever touches the amount (PRC-001, C-02).
 */
final class DecimalAmount
{
    /** Up to 11 integer digits (Money stays within ±10^13 minor units); at most two significant decimals (trailing zeros beyond them are fine). */
    private const string TEXT = '/^(0|[1-9][0-9]{0,10})(?:\.([0-9]{1,2})0*)?$/D';

    public static function toMinor(string $text, Currency $currency): Money
    {
        if (preg_match(self::TEXT, $text, $m) !== 1) {
            throw new \InvalidArgumentException('Amount must be a plain non-negative decimal with at most two decimal places.');
        }

        return new Money((int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0'), $currency);
    }

    public static function toText(Money $amount): string
    {
        if ($amount->isNegative()) {
            throw new \InvalidArgumentException('Gateway amounts are never negative.');
        }

        return intdiv($amount->minorUnits, 100) . '.' . str_pad((string) ($amount->minorUnits % 100), 2, '0', STR_PAD_LEFT);
    }
}
