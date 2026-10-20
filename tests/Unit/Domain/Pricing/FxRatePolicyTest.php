<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Domain\Pricing;

use PaxofiCloud\Domain\Pricing\FxRatePolicy;
use PaxofiCloud\Domain\Pricing\FxRateVerdict;
use PaxofiCloud\Tests\Support\PricingFixtures;
use PHPUnit\Framework\TestCase;

/** CD-16, C-27, PRC-013: hold above 5%, reprice above 3%, pause after 72 h. */
final class FxRatePolicyTest extends TestCase
{
    public function testHoldsMovesAboveFivePercent(): void
    {
        $policy = FxRatePolicy::defaults();
        $last = PricingFixtures::rates('1500', '0.9');

        self::assertSame(FxRateVerdict::Accepted, $policy->verdict(null, $last), 'the first set has nothing to compare with');
        self::assertSame(FxRateVerdict::Accepted, $policy->verdict($last, PricingFixtures::rates('1575', '0.9')), 'exactly 5%');
        self::assertSame(FxRateVerdict::Held, $policy->verdict($last, PricingFixtures::rates('1575.01', '0.9')));
        self::assertSame(FxRateVerdict::Held, $policy->verdict($last, PricingFixtures::rates('1500', '0.95')), 'any currency can trigger a hold');
        self::assertSame(FxRateVerdict::Held, $policy->verdict($last, PricingFixtures::rates('1400', '0.9')), 'drops are held too');
    }

    public function testRepricesOnlyAfterMoreThanThreePercent(): void
    {
        $policy = FxRatePolicy::defaults();
        $pricedWith = PricingFixtures::rates('1500');

        self::assertFalse($policy->warrantsRepricing($pricedWith, PricingFixtures::rates('1545')));
        self::assertTrue($policy->warrantsRepricing($pricedWith, PricingFixtures::rates('1545.1')));
    }

    public function testStalenessAfterSeventyTwoHours(): void
    {
        $policy = FxRatePolicy::defaults();
        $accepted = new \DateTimeImmutable('2026-10-20T00:00:00Z');

        self::assertTrue($policy->isStale(null, $accepted));
        self::assertFalse($policy->isStale($accepted, $accepted->modify('+72 hours')));
        self::assertTrue($policy->isStale($accepted, $accepted->modify('+72 hours +1 second')));
    }
}
