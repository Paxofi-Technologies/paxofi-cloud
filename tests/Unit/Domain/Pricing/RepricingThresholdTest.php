<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Domain\Pricing;

use PaxofiCloud\Domain\Money\Money;
use PaxofiCloud\Domain\Pricing\DerivedPrice;
use PaxofiCloud\Domain\Pricing\RepricingDecision;
use PaxofiCloud\Domain\Pricing\RepricingPolicy;
use PHPUnit\Framework\TestCase;

/** CEO decision 20 Oct 2026: ≤10% automatic, >10% approval, cost above price stops sales (CD-15, C-25, C-33). */
final class RepricingThresholdTest extends TestCase
{
    public function testDecisions(): void
    {
        $policy = RepricingPolicy::ceoDefault();
        $current = Money::of(1_000_000, 'NGN');

        self::assertSame(RepricingDecision::NoChange, $policy->decide($current, self::derived(1_000_000, 800_000)));
        self::assertSame(RepricingDecision::ApplyNextDay, $policy->decide($current, self::derived(1_058_500, 850_000)));
        self::assertSame(RepricingDecision::ApplyNextDay, $policy->decide($current, self::derived(1_100_000, 900_000)), 'exactly 10% is automatic');
        self::assertSame(RepricingDecision::NeedsApproval, $policy->decide($current, self::derived(1_100_001, 900_000)), 'just over 10% needs approval');
        self::assertSame(RepricingDecision::NeedsApproval, $policy->decide($current, self::derived(850_000, 700_000)), 'large drops need approval too');
        self::assertSame(RepricingDecision::ApplyNextDay, $policy->decide($current, self::derived(950_000, 700_000)), 'small drops are automatic');
    }

    public function testCostAtOrAboveThePriceStopsSales(): void
    {
        $policy = RepricingPolicy::ceoDefault();
        $current = Money::of(1_000_000, 'NGN');

        self::assertSame(RepricingDecision::StopSelling, $policy->decide($current, self::derived(1_050_000, 1_000_000)));
        self::assertSame(RepricingDecision::StopSelling, $policy->decide($current, self::derived(1_400_000, 1_200_000)));
    }

    private static function derived(int $price, int $cost): DerivedPrice
    {
        return new DerivedPrice(Money::of($price, 'NGN'), Money::of($cost, 'NGN'));
    }
}
