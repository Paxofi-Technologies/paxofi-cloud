<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Domain\Pricing;

use PaxofiCloud\Domain\Pricing\Decimal;
use PaxofiCloud\Domain\Pricing\DecimalRate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** CD-14, C-31: rates are exact decimal strings with one spelling each. */
final class DecimalRateTest extends TestCase
{
    public function testAcceptsPlainPositiveDecimals(): void
    {
        self::assertSame('1332.819531', (new DecimalRate('1332.819531'))->value);
        self::assertSame('0.891477', (new DecimalRate('0.891477'))->value);
        self::assertSame('1', (new DecimalRate('1'))->value);
    }

    /** @return iterable<string, array{string}> */
    public static function invalid(): iterable
    {
        yield 'zero' => ['0'];
        yield 'zero with fraction' => ['0.000000000000'];
        yield 'negative' => ['-1'];
        yield 'leading zero' => ['01.5'];
        yield 'exponent' => ['1e3'];
        yield 'thirteen fractional digits' => ['1.1234567890123'];
        yield 'ten integer digits' => ['1234567890'];
        yield 'empty' => [''];
        yield 'trailing dot' => ['1.'];
        yield 'whitespace' => [' 1'];
    }

    #[DataProvider('invalid')]
    public function testRefusesAnythingElse(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DecimalRate($value);
    }

    public function testMoveIsInBasisPointsRoundedUp(): void
    {
        $base = new DecimalRate('1500');
        self::assertSame(500, $base->moveTo(new DecimalRate('1575')));
        self::assertSame(501, $base->moveTo(new DecimalRate('1575.01')), 'just over 5% is never reported as 5%');
        self::assertSame(500, $base->moveTo(new DecimalRate('1425')), 'moves are absolute');
        self::assertSame(0, $base->moveTo(new DecimalRate('1500')));
    }

    public function testDecimalRounding(): void
    {
        self::assertSame(3, Decimal::ceilToInt('2.000000000001'));
        self::assertSame(2, Decimal::ceilToInt('2.000000000000'));
        self::assertSame(2, Decimal::ceilToInt('2'));
        self::assertSame(3, Decimal::roundHalfUpToInt('2.5'));
        self::assertSame(2, Decimal::roundHalfUpToInt('2.4999'));
        $this->expectException(\RangeException::class);
        Decimal::ceilToInt('-1');
    }

    public function testDecimalRefusesValuesBeyondEighteenDigits(): void
    {
        $this->expectException(\RangeException::class);
        Decimal::ceilToInt('1234567890123456789');
    }
}
