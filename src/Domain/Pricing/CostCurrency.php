<?php

declare(strict_types=1);

namespace PaxofiCloud\Domain\Pricing;

use PaxofiCloud\Domain\Money\Currency;

/**
 * Currencies a provider may charge us in (commerce threat model CD-13). We
 * sell only in NGN and USD (Currency), but Hetzner bills in EUR. All three
 * have two minor-unit digits.
 */
enum CostCurrency: string
{
    case NGN = 'NGN';
    case USD = 'USD';
    case EUR = 'EUR';

    /** @throws \InvalidArgumentException for any other code */
    public static function fromCode(string $code): self
    {
        return self::tryFrom($code) ?? throw new \InvalidArgumentException('Unsupported cost currency.');
    }

    public static function fromSelling(Currency $currency): self
    {
        return self::from($currency->value);
    }
}
