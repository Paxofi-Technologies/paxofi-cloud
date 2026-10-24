<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Integration\Registrar\Dynadot;

use PaxofiCloud\Domain\Pricing\CostCurrency;
use PaxofiCloud\Domain\Registrar\SkippedTld;
use PaxofiCloud\Domain\Registrar\TldCost;
use PaxofiCloud\Integration\Registrar\Dynadot\DynadotTldParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** THREAT-MODEL-DOMAIN-PRICING.md DY-5, DY-6 / DY-T3. */
final class DynadotTldParserTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function row(array $overrides = []): array
    {
        return $overrides + [
            'tld' => '.com',
            'all_years_register_price' => ['10.88', '21.76', '32.64'],
            'all_years_renew_price' => ['10.88', '21.76', '32.64'],
            'transfer_price' => '10.88',
            'min_duration' => '1',
            'max_duration' => '10',
            'on_sale' => 'no',
        ];
    }

    public function testUsesTheOneYearEntryOfTheCumulativeArrays(): void
    {
        $cost = DynadotTldParser::parse(self::row(), CostCurrency::USD);

        self::assertInstanceOf(TldCost::class, $cost);
        self::assertSame('com', $cost->tld->value);
        self::assertSame(1088, $cost->register->minor);
        self::assertSame(1088, $cost->renew->minor);
        self::assertSame(1088, $cost->transfer?->minor);
        self::assertNull($cost->restore, 'R-7: Dynadot sends no restore price');
        self::assertSame([1, 10], [$cost->minYears, $cost->maxYears]);
        self::assertSame('active', $cost->providerStatus);
    }

    public function testACheapFirstYearIsPromoAndTheRenewalKeepsItsOwnCost(): void
    {
        $cost = DynadotTldParser::parse(self::row(['tld' => 'xyz', 'all_years_register_price' => ['1.99', '9.99', '24.44'], 'all_years_renew_price' => ['14.45', '28.89']]), CostCurrency::USD);

        self::assertInstanceOf(TldCost::class, $cost);
        self::assertSame(199, $cost->register->minor);
        self::assertSame(1445, $cost->renew->minor);
        self::assertSame('promo', $cost->providerStatus);
    }

    public function testAnUnusableTransferPriceDropsOnlyThatPrice(): void
    {
        foreach (['--', '0', null, '-1'] as $transfer) {
            $cost = DynadotTldParser::parse(self::row(['transfer_price' => $transfer]), CostCurrency::USD);
            self::assertInstanceOf(TldCost::class, $cost);
            self::assertNull($cost->transfer);
        }
    }

    public function testThousandsSeparatorsAreAcceptedAndCostsRoundUp(): void
    {
        $cost = DynadotTldParser::parse(self::row(['all_years_register_price' => ['1,234.561'], 'all_years_renew_price' => ['1,234.561']]), CostCurrency::USD);

        self::assertInstanceOf(TldCost::class, $cost);
        self::assertSame(123457, $cost->register->minor);
    }

    /** @return iterable<string, array{mixed, string, ?string}> */
    public static function refusals(): iterable
    {
        yield 'not an object' => [['a', 'b'], 'not_object', null];
        yield 'bad name' => [self::row(['tld' => '../etc']), 'bad_name', null];
        yield 'no register price' => [self::row(['all_years_register_price' => []]), 'no_register_price', null];
        yield 'no renew price' => [self::row(['all_years_renew_price' => null]), 'no_renew_price', null];
        yield 'register not a list' => [self::row(['all_years_register_price' => ['1' => '9.99']]), 'bad_price', 'reg:type'];
        yield 'zero register' => [self::row(['all_years_register_price' => ['0.00']]), 'bad_price', 'reg:zero'];
        yield 'negative renew' => [self::row(['all_years_renew_price' => ['-5']]), 'bad_price', 'renew:negative'];
        yield 'text price' => [self::row(['all_years_register_price' => ['free']]), 'bad_price', 'reg:format'];
        yield 'number not text' => [self::row(['all_years_register_price' => [9.99]]), 'bad_price', 'reg:type'];
        yield 'two-year minimum' => [self::row(['min_duration' => '2']), 'no_register_price', 'periods:2'];
        yield 'bad period' => [self::row(['min_duration' => 'x']), 'bad_period', null];
    }

    #[DataProvider('refusals')]
    public function testRefusesWithAFixedReason(mixed $raw, string $reason, ?string $detail): void
    {
        $result = DynadotTldParser::parse($raw, CostCurrency::USD);

        self::assertInstanceOf(SkippedTld::class, $result);
        self::assertSame($reason, $result->reason);
        self::assertSame($detail, $result->detail);
    }
}
