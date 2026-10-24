<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Integration\Registrar\Namecheap;

use PaxofiCloud\Domain\Pricing\CostCurrency;
use PaxofiCloud\Domain\Registrar\CostAmount;
use PaxofiCloud\Integration\Provider\Http\MalformedProviderResponse;
use PaxofiCloud\Integration\Registrar\Namecheap\NamecheapPriceSheet;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** THREAT-MODEL-DOMAIN-PRICING.md NC-4 to NC-7 / DX-T3, DX-T4. */
final class NamecheapPriceSheetTest extends TestCase
{
    /** @param list<string> $prices raw <Price .../> elements for "com" */
    public static function sheet(string $category, array $prices, string $product = 'com'): string
    {
        return '<?xml version="1.0" encoding="utf-8"?>'
            . '<ApiResponse Status="OK" xmlns="http://api.namecheap.com/xml.response"><Errors />'
            . '<CommandResponse Type="namecheap.users.getPricing"><UserGetPricingResult><ProductType Name="domains">'
            . '<ProductCategory Name="' . $category . '"><Product Name="' . $product . '">' . implode('', $prices) . '</Product></ProductCategory>'
            . '</ProductType></UserGetPricingResult></CommandResponse></ApiResponse>';
    }

    private static function cost(NamecheapPriceSheet $sheet, string $product = 'com'): CostAmount|string
    {
        return $sheet->products[$product][0]->cost;
    }

    public function testReadsTheOfficialSdkFixtureShape(): void
    {
        $sheet = NamecheapPriceSheet::parse(self::sheet('register', [
            '<Price Duration="1" DurationType="YEAR" Price="8.88" PricingType="MULTIPLE" RegularPrice="10.87" YourPrice="9.99" Currency="USD" />',
            '<Price Duration="2" DurationType="YEAR" Price="17.76" RegularPrice="21.74" YourPrice="19.98" Currency="USD" />',
        ]), 'REGISTER');

        self::assertSame([], $sheet->errors);
        self::assertSame([1, 2], array_map(static fn ($t): int => $t->years, $sheet->products['com']));
        $cost = self::cost($sheet);
        self::assertInstanceOf(CostAmount::class, $cost);
        self::assertSame(999, $cost->minor, 'NC-6: the higher of Price (8.88) and YourPrice (9.99)');
        self::assertSame(CostCurrency::USD, $cost->currency);
    }

    public function testOnlyTheRequestedCategoryIsKept(): void
    {
        $sheet = NamecheapPriceSheet::parse(self::sheet('renew', ['<Price Duration="1" DurationType="YEAR" Price="11.00" Currency="USD" />']), 'REGISTER');

        self::assertSame([], $sheet->products);
    }

    /** @return iterable<string, array{string, int|string}> */
    public static function costs(): iterable
    {
        yield 'additional ICANN cost is added' => ['Price="10.00" YourPrice="9.50" AdditionalCost="0.18"', 1018];
        yield 'misspelt additional cost is added too' => ['Price="10.00" YourAdditonalCost="0.20"', 1020];
        yield 'regular price only when nothing else is set' => ['Price="0.0" YourPrice="" RegularPrice="12.00"', 1200];
        yield 'fractions of a cent round up' => ['Price="9.991"', 1000];
        yield 'all zero' => ['Price="0.0" YourPrice="0.00" RegularPrice="0"', 'zero'];
        yield 'all missing' => ['', 'missing'];
        yield 'exponent refused' => ['Price="1e3"', 'format'];
        yield 'negative refused' => ['Price="-1.00"', 'negative'];
    }

    #[DataProvider('costs')]
    public function testCostRules(string $attributes, int|string $expected): void
    {
        $sheet = NamecheapPriceSheet::parse(self::sheet('register', ['<Price Duration="1" DurationType="YEAR" ' . $attributes . ' Currency="USD" />']), 'register');
        $cost = self::cost($sheet);

        self::assertSame($expected, $cost instanceof CostAmount ? $cost->minor : $cost);
    }

    public function testPromotionIsFlaggedButNeverLowersTheCost(): void
    {
        $sheet = NamecheapPriceSheet::parse(self::sheet('register', [
            '<Price Duration="1" DurationType="YEAR" Price="5.98" YourPrice="10.98" PromotionPrice="5.98" Currency="USD" />',
            '<Price Duration="2" DurationType="YEAR" Price="21.96" PromotionPrice="0.0" Currency="USD" />',
        ]), 'register');

        [$one, $two] = $sheet->products['com'];
        self::assertTrue($one->promotion);
        self::assertSame(1098, $one->cost instanceof CostAmount ? $one->cost->minor : null);
        self::assertFalse($two->promotion, 'PromotionPrice="0.0" is no promotion');
    }

    public function testCurrencyProblemsAreNamed(): void
    {
        $sheet = NamecheapPriceSheet::parse(self::sheet('register', ['<Price Duration="1" DurationType="YEAR" Price="9.00" Currency="TRY" />']), 'register');
        self::assertSame('unsupported_currency', self::cost($sheet));
        self::assertSame('TRY', $sheet->products['com'][0]->currencyDetail);

        $sheet = NamecheapPriceSheet::parse(self::sheet('register', ['<Price Duration="1" DurationType="YEAR" Price="9.00" />']), 'register');
        self::assertSame('currency:missing', $sheet->products['com'][0]->currencyDetail);
    }

    public function testOnlyWholeYearTermsUpToTenAreRead(): void
    {
        $sheet = NamecheapPriceSheet::parse(self::sheet('register', [
            '<Price Duration="1" DurationType="MONTH" Price="1.00" Currency="USD" />',
            '<Price Duration="11" DurationType="YEAR" Price="99.00" Currency="USD" />',
            '<Price Duration="0" DurationType="YEAR" Price="1.00" Currency="USD" />',
            '<Price Duration="10" DurationType="year" Price="90.00" Currency="USD" />',
        ]), 'register');

        self::assertSame([10], array_map(static fn ($t): int => $t->years, $sheet->products['com']));
    }

    public function testErrorResponsesReturnTheirNumbersOnly(): void
    {
        $xml = '<?xml version="1.0"?><ApiResponse Status="ERROR"><Errors><Error Number="1011150">Parameter RequestIP is invalid</Error>'
            . '<Error Number="evil text">x</Error></Errors></ApiResponse>';

        self::assertSame(['1011150', 'unknown'], NamecheapPriceSheet::parse($xml, 'register')->errors);
    }

    /** @return iterable<string, array{string}> */
    public static function hostile(): iterable
    {
        yield 'external entity (XXE)' => ['<?xml version="1.0"?><!DOCTYPE r [<!ENTITY x SYSTEM "file:///etc/passwd">]><ApiResponse Status="OK">&x;</ApiResponse>'];
        yield 'billion laughs' => ['<?xml version="1.0"?><!DOCTYPE l [<!ENTITY a "aaaa"><!ENTITY b "&a;&a;&a;&a;">]><ApiResponse Status="OK">&b;</ApiResponse>'];
        yield 'lower-case doctype' => ['<?xml version="1.0"?><!doctype r><ApiResponse Status="OK"/>'];
        yield 'not XML' => ['{"ok":true}'];
        yield 'truncated' => ['<?xml version="1.0"?><ApiResponse Status="OK"><CommandResponse>'];
        yield 'empty' => [''];
        yield 'no status' => ['<?xml version="1.0"?><ApiResponse/>'];
        yield 'too deep' => ['<?xml version="1.0"?><ApiResponse Status="OK">' . str_repeat('<a>', 12) . str_repeat('</a>', 12) . '</ApiResponse>'];
    }

    #[DataProvider('hostile')]
    public function testHostileOrBrokenXmlIsRefused(string $xml): void
    {
        $this->expectException(MalformedProviderResponse::class);

        NamecheapPriceSheet::parse($xml, 'register');
    }

    public function testTooManyProductsAreRefused(): void
    {
        $products = str_repeat('<Product Name="x"><Price Duration="1" DurationType="YEAR" Price="1.00" Currency="USD" /></Product>', NamecheapPriceSheet::MAX_PRODUCTS + 1);
        $xml = '<?xml version="1.0"?><ApiResponse Status="OK"><CommandResponse><UserGetPricingResult><ProductType Name="domains"><ProductCategory Name="register">'
            . $products . '</ProductCategory></ProductType></UserGetPricingResult></CommandResponse></ApiResponse>';

        $this->expectException(MalformedProviderResponse::class);

        NamecheapPriceSheet::parse($xml, 'register');
    }
}
