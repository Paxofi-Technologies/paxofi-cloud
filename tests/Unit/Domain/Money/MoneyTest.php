<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Domain\Money;

use PaxofiCloud\Domain\Money\BasisPoints;
use PaxofiCloud\Domain\Money\Currency;
use PaxofiCloud\Domain\Money\CurrencyMismatch;
use PaxofiCloud\Domain\Money\Money;
use PaxofiCloud\Domain\Money\MoneyOutOfRange;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** PRC-001, PRC-010; commerce threat model C-02, C-03. */
final class MoneyTest extends TestCase
{
    public function testArithmeticStaysInMinorUnits(): void
    {
        $a = Money::of(1_000_050, 'NGN');
        $b = Money::of(25, 'NGN');

        self::assertSame(1_000_075, $a->add($b)->minorUnits);
        self::assertSame(1_000_025, $a->subtract($b)->minorUnits);
        self::assertSame(3_000_150, $a->times(3)->minorUnits);
        self::assertSame(Currency::NGN, $a->add($b)->currency);
        self::assertSame(2_000_200, Money::sum(Currency::NGN, [$a, $a, Money::of(100, 'NGN')])->minorUnits);
        self::assertTrue(Money::zero(Currency::USD)->isZero());
        self::assertTrue(Money::of(-1, 'USD')->isNegative());
        self::assertSame(-1, $b->compare($a));
        self::assertTrue($b->equals(Money::of(25, 'NGN')));
    }

    public function testCurrenciesNeverMix(): void
    {
        $naira = Money::of(100, 'NGN');
        $dollar = Money::of(100, 'USD');
        foreach ([fn () => $naira->add($dollar), fn () => $naira->subtract($dollar), fn () => $naira->compare($dollar), fn () => $naira->equals($dollar), fn () => Money::sum(Currency::USD, [$naira])] as $operation) {
            try {
                $operation();
                self::fail('Mixed currencies were combined');
            } catch (CurrencyMismatch) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testOnlyLaunchCurrenciesAreAccepted(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::of(100, 'EUR');
    }

    public function testAmountsAreBoundedSoNothingOverflows(): void
    {
        self::assertSame(Money::MAX_MINOR_UNITS, Money::of(Money::MAX_MINOR_UNITS, 'USD')->minorUnits);
        foreach ([
            fn () => Money::of(Money::MAX_MINOR_UNITS + 1, 'USD'),
            fn () => Money::of(-Money::MAX_MINOR_UNITS - 1, 'USD'),
            fn () => Money::of(Money::MAX_MINOR_UNITS, 'USD')->add(Money::of(1, 'USD')),
            fn () => Money::of(Money::MAX_MINOR_UNITS, 'USD')->times(2),
            fn () => Money::of(1, 'USD')->times(1001),
            fn () => Money::of(1, 'USD')->times(-1),
        ] as $operation) {
            try {
                $operation();
                self::fail('Out-of-range amount accepted');
            } catch (MoneyOutOfRange) {
                self::addToAssertionCount(1);
            }
        }
        // The largest amount times the largest rate cannot overflow a 64-bit integer.
        self::assertSame(Money::MAX_MINOR_UNITS, Money::of(Money::MAX_MINOR_UNITS, 'USD')->percentage(new BasisPoints(10_000))->minorUnits);
    }

    /** PRC-010 acceptance: 15% of 3,333 kobo is 500 kobo (499.95 rounded half-up). */
    #[DataProvider('percentages')]
    public function testPercentagesRoundHalfUpAtTheMinorUnit(int $amount, int $basisPoints, int $expected): void
    {
        self::assertSame($expected, Money::of($amount, 'NGN')->percentage(new BasisPoints($basisPoints))->minorUnits);
    }

    /** @return iterable<string, array{int, int, int}> */
    public static function percentages(): iterable
    {
        yield 'SRS PRC-010' => [3_333, 1_500, 500];
        yield 'SRS PRC-008 VAT' => [1_000_000, 750, 75_000];
        yield 'exactly half rounds up' => [10, 500, 1];
        yield 'just under half rounds down' => [9, 500, 0];
        yield 'negative rounds away from zero' => [-10, 500, -1];
        yield 'zero rate' => [123_456, 0, 0];
        yield 'full rate' => [123_456, 10_000, 123_456];
    }

    public function testBasisPointsAreBounded(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new BasisPoints(10_001);
    }
}
