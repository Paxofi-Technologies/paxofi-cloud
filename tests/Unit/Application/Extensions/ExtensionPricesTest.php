<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Application\Extensions;

use PaxofiCloud\Application\Extensions\ExtensionPrices;
use PaxofiCloud\Domain\Money\BasisPoints;
use PaxofiCloud\Domain\Pricing\CostCurrency;
use PaxofiCloud\Domain\Pricing\DecimalRate;
use PaxofiCloud\Domain\Pricing\DomainPricingSettings;
use PaxofiCloud\Domain\Pricing\ExchangeRates;
use PaxofiCloud\Domain\Pricing\Markup;
use PaxofiCloud\Domain\Pricing\RoundingStep;
use PaxofiCloud\Domain\Registrar\CostAmount;
use PaxofiCloud\Domain\Registrar\TldCost;
use PaxofiCloud\Domain\Registrar\TldName;
use PHPUnit\Framework\TestCase;

/** THREAT-MODEL-DOMAIN-PRICING.md DX-1, DX-5 / DX-T4, DX-T5. Test numbers only; real margins live in the admin console. */
final class ExtensionPricesTest extends TestCase
{
    private static function rates(string $ngn = '1500'): ExchangeRates
    {
        return new ExchangeRates(['USD' => new DecimalRate('1'), 'NGN' => new DecimalRate($ngn), 'EUR' => new DecimalRate('0.9')], 'test', new \DateTimeImmutable('2026-10-25T00:00:00Z'));
    }

    private static function settings(int $markup = 2500, int $floor = 1000, int $usdStep = 1, int $ngnStep = 5000): DomainPricingSettings
    {
        return new DomainPricingSettings(new Markup($markup), new BasisPoints($floor), new RoundingStep($usdStep), new RoundingStep($ngnStep));
    }

    public function testUsdIsMarkedUpAndNgnIsTheUsdPriceConvertedAndRoundedUp(): void
    {
        $usd = CostCurrency::USD;
        $cost = new TldCost(new TldName('com'), CostAmount::ofMinor($usd, 1000), CostAmount::ofMinor($usd, 1001), null, CostAmount::ofMinor($usd, 8000), 1, 10, 'active');

        $prices = ExtensionPrices::for($cost, self::settings(), self::rates());

        self::assertSame(['register' => 1250, 'renew' => 1252, 'transfer' => null, 'restore' => 10000], $prices->usd, '25% up, rounded up to the cent; no transfer cost = not sold');
        // 12.50 USD × 1500 = 18,750 NGN = 1,875,000 kobo, already on a ₦50 step; 12.52 × 1500 = 18,780 → ₦18,800.
        self::assertSame(['register' => 1_875_000, 'renew' => 1_880_000, 'transfer' => null, 'restore' => 15_000_000], $prices->ngn);
    }

    public function testRoundingStepsRoundUp(): void
    {
        $usd = CostCurrency::USD;
        $cost = new TldCost(new TldName('ng'), CostAmount::ofMinor($usd, 999), CostAmount::ofMinor($usd, 999), null, null, 1, 1, 'active');

        $prices = ExtensionPrices::for($cost, self::settings(2000, 1000, 100), self::rates('1501.37'));

        self::assertSame(1200, $prices->usd['register'], '11.988 → 12.00 on a whole-dollar step');
        self::assertSame(1_805_000, $prices->ngn['register'], '12 × 1501.37 = 18,016.44 → ₦18,050');
    }

    public function testTheMinimumMarginCannotExceedTheMarkup(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::settings(500, 1000);
    }
}
