<?php

declare(strict_types=1);

namespace PaxofiCloud\Domain\Registrar;

use PaxofiCloud\Domain\Pricing\CostCurrency;
use PaxofiCloud\Domain\Pricing\Decimal;

/**
 * A provider cost in integer minor units (cents, kobo). Feeds may quote up to
 * eight decimal places; the amount is rounded **up** to the minor unit so a
 * stored cost is never below the real one (DR-5). Built from decimal text only.
 */
final readonly class CostAmount
{
    /** Largest accepted cost: one billion major units. */
    private const string PATTERN = '/^(0|[1-9][0-9]{0,8})(\.[0-9]{1,8})?$/';

    private function __construct(public CostCurrency $currency, public int $minor)
    {
    }

    /** @throws \InvalidArgumentException for anything but a positive plain decimal */
    public static function fromDecimalText(CostCurrency $currency, string $amount): self
    {
        if (preg_match(self::PATTERN, $amount, $m) !== 1) {
            throw new \InvalidArgumentException('Cost must be a plain non-negative decimal with at most 8 places.');
        }
        $fraction = str_pad(substr($m[2] ?? '', 1), 8, '0');
        // whole * 100 + fraction/100, rounded up, as text: no float, no bcmath needed.
        $minor = Decimal::ceilToInt($m[1] . substr($fraction, 0, 2) . '.' . substr($fraction, 2));
        if ($minor <= 0) {
            throw new \InvalidArgumentException('Cost must be positive.');
        }

        return new self($currency, $minor);
    }

    public static function ofMinor(CostCurrency $currency, int $minor): self
    {
        if ($minor <= 0 || $minor > 100_000_000_000) {
            throw new \InvalidArgumentException('Cost must be positive and within bounds.');
        }

        return new self($currency, $minor);
    }
}
