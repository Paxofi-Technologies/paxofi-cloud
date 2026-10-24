<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Domain\Registrar;

use PaxofiCloud\Domain\Pricing\CostCurrency;
use PaxofiCloud\Domain\Registrar\CostAmount;
use PaxofiCloud\Domain\Registrar\TldName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** DR-5: costs from decimal text, rounded up to the minor unit; DR-7 extension names. */
final class CostAmountTest extends TestCase
{
    /** @return iterable<string, array{string, int}> */
    public static function amounts(): iterable
    {
        yield 'whole' => ['12', 1200];
        yield 'two places' => ['10.81', 1081];
        yield 'four places, exact' => ['10.8100', 1081];
        yield 'rounds up a fraction of a cent' => ['10.8101', 1082];
        yield 'eight places rounds up' => ['8.99000001', 900];
        yield 'smallest' => ['0.0001', 1];
        yield 'large' => ['999999999.99', 99_999_999_999];
    }

    #[DataProvider('amounts')]
    public function testConvertsDecimalTextRoundingUp(string $text, int $minor): void
    {
        self::assertSame($minor, CostAmount::fromDecimalText(CostCurrency::USD, $text)->minor);
    }

    /** @return iterable<string, array{string}> */
    public static function refused(): iterable
    {
        yield 'zero' => ['0'];
        yield 'zero with places' => ['0.00'];
        yield 'negative' => ['-1.00'];
        yield 'exponent' => ['1e3'];
        yield 'nine places' => ['1.000000001'];
        yield 'leading zero' => ['01.5'];
        yield 'too large' => ['1000000000'];
        yield 'comma' => ['1,50'];
        yield 'empty' => [''];
        yield 'spaces' => [' 1.5'];
    }

    #[DataProvider('refused')]
    public function testRefusesAnythingButAPositivePlainDecimal(string $text): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CostAmount::fromDecimalText(CostCurrency::USD, $text);
    }

    public function testExtensionNames(): void
    {
        self::assertSame('com.ng', TldName::fromFeed('.COM.NG')->value);
        self::assertSame('xn--p1ai', TldName::fromFeed('xn--p1ai')->value);
        foreach (['', '.', 'com..ng', '-com', 'com-', 'c_m', 'a.b.c.d', '123', 'com/ng', 'com ng', str_repeat('a', 64)] as $bad) {
            try {
                TldName::fromFeed($bad);
                self::fail('Accepted ' . $bad);
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
