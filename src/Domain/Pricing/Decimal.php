<?php

declare(strict_types=1);

namespace PaxofiCloud\Domain\Pricing;

/**
 * Rounding of non-negative bcmath decimal strings to integers. Pricing works
 * on decimal strings so nothing passes through a float (CD-14).
 */
final class Decimal
{
    private const int MAX_DIGITS = 18;

    /** The smallest integer >= $value. @throws \RangeException */
    public static function ceilToInt(string $value): int
    {
        [$whole, $fraction] = self::split($value);
        $result = self::toInt($whole);

        return trim($fraction, '0') === '' ? $result : $result + 1;
    }

    /** Half-up rounding to an integer. @throws \RangeException */
    public static function roundHalfUpToInt(string $value): int
    {
        [$whole, $fraction] = self::split($value);
        $result = self::toInt($whole);

        return $fraction !== '' && $fraction[0] >= '5' ? $result + 1 : $result;
    }

    /** @return array{string, string} */
    private static function split(string $value): array
    {
        if (preg_match('/^([0-9]+)(?:\.([0-9]*))?$/', $value, $m) !== 1) {
            throw new \RangeException('Expected a non-negative decimal.');
        }

        return [$m[1], $m[2] ?? ''];
    }

    private static function toInt(string $whole): int
    {
        $whole = ltrim($whole, '0');
        if (strlen($whole) > self::MAX_DIGITS) {
            throw new \RangeException('Decimal is too large.');
        }

        return (int) ($whole === '' ? '0' : $whole);
    }
}
