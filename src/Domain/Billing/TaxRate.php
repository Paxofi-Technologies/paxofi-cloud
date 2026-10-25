<?php

declare(strict_types=1);

namespace PaxofiCloud\Domain\Billing;

use PaxofiCloud\Domain\Money\BasisPoints;
use PaxofiCloud\Domain\Money\Money;

/** One row of the tax table in force for a country (commerce threat model CD-22). */
final readonly class TaxRate
{
    public const string FALLBACK_COUNTRY = 'ZZ';

    public function __construct(public string $country, public string $name, public BasisPoints $rate)
    {
        if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            throw new \InvalidArgumentException('Tax country must be ISO 3166-1 alpha-2.');
        }
        if ($name === '' || mb_strlen($name) > 40) {
            throw new \InvalidArgumentException('Tax name must be 1–40 characters.');
        }
    }

    /** Tax on one taxable line, half-up to the minor unit (CD-2). */
    public function on(Money $line): Money
    {
        return $line->percentage($this->rate);
    }
}
