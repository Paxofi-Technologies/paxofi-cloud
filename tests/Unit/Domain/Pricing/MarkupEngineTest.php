<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Domain\Pricing;

use PaxofiCloud\Domain\Money\BasisPoints;
use PaxofiCloud\Domain\Money\Currency;
use PaxofiCloud\Domain\Pricing\BelowMarginFloor;
use PaxofiCloud\Domain\Pricing\CostCurrency;
use PaxofiCloud\Domain\Pricing\Markup;
use PaxofiCloud\Domain\Pricing\PriceDeriver;
use PaxofiCloud\Domain\Pricing\RoundingStep;
use PaxofiCloud\Tests\Support\PricingFixtures;
use PHPUnit\Framework\TestCase;

/**
 * Worked examples for PRC-012 / CD-14 (commerce threat model C-31), at
 * illustrative rates of ₦1500 and €0.90 per US dollar. Not real prices.
 */
final class MarkupEngineTest extends TestCase
{
    private PriceDeriver $deriver;

    protected function setUp(): void
    {
        $this->deriver = new PriceDeriver(new BasisPoints(1_000));
    }

    public function testDollarCostToNairaPriceRoundedUpToTheStep(): void
    {
        // $10.00 cost → ₦15,000 → +25% = ₦18,750 → next ₦100 = ₦18,800.
        $price = $this->deriver->derive(1_000, CostCurrency::USD, Currency::NGN, PricingFixtures::rates(), new Markup(2_500), new RoundingStep(10_000));

        self::assertSame(1_880_000, $price->price->minorUnits);
        self::assertSame(Currency::NGN, $price->price->currency);
        self::assertSame(1_500_000, $price->convertedCost->minorUnits);
    }

    public function testSameCurrencyIsNotConverted(): void
    {
        // $10.00 → +25% = $12.50, already on a 50-cent step.
        $price = $this->deriver->derive(1_000, CostCurrency::USD, Currency::USD, PricingFixtures::rates(), new Markup(2_500), new RoundingStep(50));

        self::assertSame(1_250, $price->price->minorUnits);
    }

    public function testEuroCostRoundsUpAtEveryStep(): void
    {
        // €4.51 → $5.0111… → 502 cents (cost rounded up) → +30% = 652.6 → 653 → next 50 cents = $7.00.
        $usd = $this->deriver->derive(451, CostCurrency::EUR, Currency::USD, PricingFixtures::rates(), new Markup(3_000), new RoundingStep(50));
        self::assertSame(502, $usd->convertedCost->minorUnits);
        self::assertSame(700, $usd->price->minorUnits);

        // €4.51 → ₦7,516.666… → 751,667 kobo → +30% = 977,167.1 → 977,168 → next ₦100 = ₦9,800.
        $ngn = $this->deriver->derive(451, CostCurrency::EUR, Currency::NGN, PricingFixtures::rates(), new Markup(3_000), new RoundingStep(10_000));
        self::assertSame(751_667, $ngn->convertedCost->minorUnits);
        self::assertSame(980_000, $ngn->price->minorUnits);
    }

    public function testAMarkupBelowTheMinimumMarginIsRefused(): void
    {
        $this->expectException(BelowMarginFloor::class);
        // +5% gives $10.50; the floor is cost + 10% = $11.00.
        $this->deriver->derive(1_000, CostCurrency::USD, Currency::USD, PricingFixtures::rates(), new Markup(500), new RoundingStep(50));
    }

    public function testRoundingCanLiftAPriceOverTheFloor(): void
    {
        // +5% gives $10.50, rounded up to a whole dollar step = $11.00, which meets the floor.
        $price = $this->deriver->derive(1_000, CostCurrency::USD, Currency::USD, PricingFixtures::rates(), new Markup(500), new RoundingStep(100));
        self::assertSame(1_100, $price->price->minorUnits);
    }

    public function testRefusesAZeroCost(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->deriver->derive(0, CostCurrency::USD, Currency::USD, PricingFixtures::rates(), new Markup(2_500), new RoundingStep(50));
    }

    public function testBoundsOnMarkupAndStep(): void
    {
        new Markup(Markup::MAX);
        new RoundingStep(1);
        foreach ([static fn () => new Markup(-1), static fn () => new Markup(Markup::MAX + 1), static fn () => new RoundingStep(0)] as $make) {
            try {
                $make();
                self::fail('out-of-range value accepted');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
