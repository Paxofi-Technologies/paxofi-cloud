<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Support;

use PaxofiCloud\Domain\Pricing\DecimalRate;
use PaxofiCloud\Domain\Pricing\ExchangeRates;

/** Round illustrative rates for pricing tests: ₦1500 and €0.90 per US dollar. */
final class PricingFixtures
{
    public static function rates(string $ngn = '1500', string $eur = '0.9', string $source = 'test'): ExchangeRates
    {
        return new ExchangeRates(
            ['NGN' => new DecimalRate($ngn), 'USD' => new DecimalRate('1'), 'EUR' => new DecimalRate($eur)],
            $source,
            new \DateTimeImmutable('2026-10-20T00:00:00Z'),
        );
    }
}
