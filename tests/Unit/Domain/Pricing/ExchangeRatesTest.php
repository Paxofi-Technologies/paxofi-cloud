<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Domain\Pricing;

use PaxofiCloud\Domain\Money\Currency;
use PaxofiCloud\Domain\Pricing\CostCurrency;
use PaxofiCloud\Domain\Pricing\DecimalRate;
use PaxofiCloud\Domain\Pricing\ExchangeRates;
use PaxofiCloud\Tests\Support\PricingFixtures;
use PHPUnit\Framework\TestCase;

final class ExchangeRatesTest extends TestCase
{
    public function testConvertsThroughTheDollarExactly(): void
    {
        $rates = PricingFixtures::rates();
        self::assertSame('1500000.000000000000', $rates->convert(1_000, CostCurrency::USD, Currency::NGN));
        self::assertSame('1000', $rates->convert(1_000, CostCurrency::USD, Currency::USD), 'same currency is not converted');
        self::assertSame('1000.000000000000', $rates->convert(1_500_000, CostCurrency::NGN, Currency::USD));
        // €4.51 = 451 cents ÷ 0.9 × 1500 kobo.
        self::assertSame('751666.666666666666', $rates->convert(451, CostCurrency::EUR, Currency::NGN));
    }

    public function testHandlesTheLargestAmountsWithoutOverflow(): void
    {
        $converted = PricingFixtures::rates('999999999')->convert(10_000_000_000_000, CostCurrency::USD, Currency::NGN);
        self::assertSame('9999999990000000000000.000000000000', $converted);
    }

    public function testRequiresEveryCurrencyAndAUnitDollar(): void
    {
        try {
            new ExchangeRates(['NGN' => new DecimalRate('1500'), 'USD' => new DecimalRate('1')], 't', new \DateTimeImmutable());
            self::fail('a missing EUR rate must be refused');
        } catch (\InvalidArgumentException) {
        }
        $this->expectException(\InvalidArgumentException::class);
        new ExchangeRates(['NGN' => new DecimalRate('1500'), 'USD' => new DecimalRate('1.01'), 'EUR' => new DecimalRate('0.9')], 't', new \DateTimeImmutable());
    }

    public function testRefusesNegativeAmounts(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PricingFixtures::rates()->convert(-1, CostCurrency::USD, Currency::NGN);
    }
}
