<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Domain\Registrar;

use PaxofiCloud\Domain\Pricing\CostCurrency;
use PaxofiCloud\Domain\Registrar\CostQuarantinePolicy;
use PaxofiCloud\Tests\Support\ScriptedPriceFeed;
use PHPUnit\Framework\TestCase;

/** CD-13 / C-25 / DR-8: implausible cost lists are held, not used. */
final class CostQuarantinePolicyTest extends TestCase
{
    /** @return array<string, int> ten extensions at 1000 minor units */
    private static function ten(): array
    {
        $prices = [];
        foreach (range(1, 10) as $i) {
            $prices['ext' . $i] = 1000;
        }

        return $prices;
    }

    public function testFirstListIsAcceptedUnlessEmpty(): void
    {
        $policy = new CostQuarantinePolicy();

        self::assertNull($policy->assess(null, ScriptedPriceFeed::list(['com' => 999])));
        self::assertSame('empty', $policy->assess(null, ScriptedPriceFeed::list([])));
        self::assertSame('empty', $policy->assess(ScriptedPriceFeed::list(self::ten())->items, ScriptedPriceFeed::list([])));
    }

    public function testMissingExtensions(): void
    {
        $policy = new CostQuarantinePolicy();
        $previous = ScriptedPriceFeed::list(self::ten())->items;

        $twoGone = array_slice(self::ten(), 2, null, true);
        self::assertNull($policy->assess($previous, ScriptedPriceFeed::list($twoGone)), '20% missing is at the limit and accepted');

        $threeGone = array_slice(self::ten(), 3, null, true);
        self::assertSame('items_disappeared', $policy->assess($previous, ScriptedPriceFeed::list($threeGone)));

        self::assertNull($policy->assess($previous, ScriptedPriceFeed::list(self::ten() + ['new1' => 1, 'new2' => 1, 'new3' => 1])), 'new extensions are fine');
    }

    public function testLargeCostChanges(): void
    {
        $policy = new CostQuarantinePolicy();
        $previous = ScriptedPriceFeed::list(self::ten())->items;

        $twoJumped = ['ext1' => 1501, 'ext2' => 499] + self::ten();
        self::assertNull($policy->assess($previous, ScriptedPriceFeed::list($twoJumped)), '2 of 10 is not more than 20%');

        $threeJumped = ['ext1' => 1501, 'ext2' => 499, 'ext3' => 1] + self::ten();
        self::assertSame('costs_jumped', $policy->assess($previous, ScriptedPriceFeed::list($threeJumped)));

        $exactlyHalf = ['ext1' => 1500, 'ext2' => 1500, 'ext3' => 500] + self::ten();
        self::assertNull($policy->assess($previous, ScriptedPriceFeed::list($exactlyHalf)), 'a 50% change is at the limit');

        $allSmall = array_map(static fn (int $minor): int => $minor + 400, self::ten());
        self::assertNull($policy->assess($previous, ScriptedPriceFeed::list($allSmall)), 'many small changes are fine; repricing rules handle them');
    }

    public function testCurrencyChangeCountsAsAJump(): void
    {
        $policy = new CostQuarantinePolicy();

        $result = $policy->assess(ScriptedPriceFeed::list(self::ten())->items, ScriptedPriceFeed::list(self::ten(), 'live', CostCurrency::EUR));

        self::assertSame('costs_jumped', $result);
    }

    public function testThresholdsMustBePercentages(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CostQuarantinePolicy(0);
    }
}
