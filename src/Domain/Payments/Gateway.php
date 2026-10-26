<?php

declare(strict_types=1);

namespace PaxofiCloud\Domain\Payments;

use PaxofiCloud\Domain\Money\Currency;
use PaxofiCloud\Domain\Money\Money;

/** The payment gateways PaxofiCloud collects through at launch (PAY-001, THREAT-MODEL-PAYMENTS.md PY-1). */
enum Gateway: string
{
    case Paystack = 'paystack';
    case Flutterwave = 'flutterwave';

    public function label(): string
    {
        return match ($this) {
            self::Paystack => 'Paystack',
            self::Flutterwave => 'Flutterwave',
        };
    }

    /**
     * The smallest amount the gateway accepts in a currency, in minor units
     * (Paystack: NGN 50.00, USD 2.00). Null when the gateway publishes none;
     * its own refusal then applies.
     */
    public function minimum(Currency $currency): ?Money
    {
        return match ($this) {
            self::Paystack => new Money($currency === Currency::NGN ? 5_000 : 200, $currency),
            self::Flutterwave => null,
        };
    }
}
