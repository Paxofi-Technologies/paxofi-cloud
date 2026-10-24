<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Integration\Registrar\DomainNameApi;

use PaxofiCloud\Domain\Pricing\CostCurrency;
use PaxofiCloud\Domain\Registrar\SkippedTld;
use PaxofiCloud\Domain\Registrar\TldCost;
use PaxofiCloud\Integration\Provider\Http\ExactJson;
use PaxofiCloud\Integration\Registrar\DomainNameApi\DomainNameApiTldParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** DR-7: strict per-item validation of the DomainNameAPI price list. */
final class DomainNameApiTldParserTest extends TestCase
{
    /** @param array<string, mixed> $overrides */
    public static function rawItem(array $overrides = []): string
    {
        $item = array_replace([
            'name' => 'com',
            'status' => 'Active',
            'minRegistrationPeriod' => 1,
            'maxRegistrationPeriod' => 10,
            'prices' => [[
                'register' => ['price' => '__REG__', 'currency' => 'USD', 'period' => 1],
                'renew' => ['price' => '__REN__', 'currency' => 'USD', 'period' => 1],
                'transfer' => ['price' => '__TRA__', 'currency' => 'USD', 'period' => 1],
            ]],
        ], $overrides);

        // Prices as JSON numbers, exactly as a provider would send them.
        return str_replace(['"__REG__"', '"__REN__"', '"__TRA__"'], ['10.8100', '11.2500', '10.8100'], json_encode($item, JSON_THROW_ON_ERROR));
    }

    public function testParsesAWellFormedItemFromJsonNumbers(): void
    {
        $cost = DomainNameApiTldParser::item(ExactJson::decode(self::rawItem()));

        self::assertInstanceOf(TldCost::class, $cost);
        self::assertSame('com', $cost->tld->value);
        self::assertSame([1081, 1125, 1081], [$cost->register->minor, $cost->renew->minor, $cost->transfer?->minor]);
        self::assertSame(CostCurrency::USD, $cost->register->currency);
        self::assertNull($cost->restore);
        self::assertSame([1, 10, 'active'], [$cost->minYears, $cost->maxYears, $cost->providerStatus]);
    }

    public function testPicksTheOneYearEntryFromAPerPeriodList(): void
    {
        $item = ExactJson::decode(self::rawItem(['prices' => [[
            'register' => [['price' => '__REG__', 'currency' => 'usd', 'period' => 2], ['price' => '__REN__', 'currency' => 'usd', 'period' => 1]],
            'renew' => ['price' => '__TRA__', 'currency' => 'USD'],
        ]]]));

        $cost = DomainNameApiTldParser::item($item);

        self::assertInstanceOf(TldCost::class, $cost);
        self::assertSame(1125, $cost->register->minor, 'the 1-year entry, not the first one');
        self::assertSame(1081, $cost->renew->minor, 'a single object without period counts as 1 year');
        self::assertNull($cost->transfer);
    }

    public function testCapsTheMaximumPeriodAtTenYears(): void
    {
        $cost = DomainNameApiTldParser::item(ExactJson::decode(self::rawItem(['maxRegistrationPeriod' => 99])));

        self::assertInstanceOf(TldCost::class, $cost);
        self::assertSame(10, $cost->maxYears);
    }

    /** @return iterable<string, array{string, string}> */
    public static function refusals(): iterable
    {
        yield 'list instead of object' => ['[1,2]', 'not_object'];
        yield 'missing name' => [self::rawItem(['name' => null]), 'bad_name'];
        yield 'name with path' => [self::rawItem(['name' => 'com/../x']), 'bad_name'];
        yield 'numeric name' => [self::rawItem(['name' => '123']), 'bad_name'];
        yield 'status with spaces' => [self::rawItem(['status' => 'on sale!']), 'bad_status'];
        yield 'zero minimum period' => [self::rawItem(['minRegistrationPeriod' => 0]), 'bad_period'];
        yield 'fractional period' => [self::rawItem(['minRegistrationPeriod' => 1.5]), 'bad_period'];
        yield 'min above max' => [self::rawItem(['minRegistrationPeriod' => 5, 'maxRegistrationPeriod' => 2]), 'bad_period'];
        yield 'no prices' => [self::rawItem(['prices' => []]), 'no_prices'];
        yield 'no register price' => [self::rawItem(['prices' => [['renew' => ['price' => '1', 'currency' => 'USD']]]]), 'no_register_price'];
        yield 'no renew price' => [self::rawItem(['prices' => [['register' => ['price' => '1', 'currency' => 'USD']]]]), 'no_renew_price'];
        yield 'register only for 2 years' => [self::rawItem(['prices' => [['register' => ['price' => '1', 'currency' => 'USD', 'period' => 2], 'renew' => ['price' => '1', 'currency' => 'USD']]]]), 'no_register_price'];
        yield 'zero price' => [self::rawItem(['prices' => [['register' => ['price' => 0, 'currency' => 'USD'], 'renew' => ['price' => '1', 'currency' => 'USD']]]]), 'bad_price'];
        yield 'negative price' => [self::rawItem(['prices' => [['register' => ['price' => -5, 'currency' => 'USD'], 'renew' => ['price' => '1', 'currency' => 'USD']]]]), 'bad_price'];
        yield 'exponent price' => [str_replace('10.8100', '1.081e1', self::rawItem()), 'bad_price'];
        yield 'price as text with words' => [self::rawItem(['prices' => [['register' => ['price' => 'ten', 'currency' => 'USD'], 'renew' => ['price' => '1', 'currency' => 'USD']]]]), 'bad_price'];
        yield 'Turkish lira' => [self::rawItem(['prices' => [['register' => ['price' => '1', 'currency' => 'TRY'], 'renew' => ['price' => '1', 'currency' => 'USD']]]]), 'unsupported_currency'];
        yield 'register in USD, renew in EUR' => [self::rawItem(['prices' => [['register' => ['price' => '1', 'currency' => 'USD'], 'renew' => ['price' => '1', 'currency' => 'EUR']]]]), 'mixed_currency'];
        yield 'missing currency' => [self::rawItem(['prices' => [['register' => ['price' => '1'], 'renew' => ['price' => '1', 'currency' => 'USD']]]]), 'unsupported_currency'];
    }

    #[DataProvider('refusals')]
    public function testRefusesBadItemsWithAFixedReason(string $json, string $reason): void
    {
        self::assertSame($reason, DomainNameApiTldParser::item(ExactJson::decode($json)));
        self::assertContains($reason, DomainNameApiTldParser::REASONS);
    }

    /** DR-14: a skipped item keeps its name, reason and a short detail. */
    public function testSkippedItemsKeepNameReasonAndDetail(): void
    {
        $try = DomainNameApiTldParser::parse(ExactJson::decode(self::rawItem(['name' => '.COM.TR', 'prices' => [[
            'register' => ['price' => '__REG__', 'currency' => 'try', 'period' => 1],
            'renew' => ['price' => '__REN__', 'currency' => 'TRY', 'period' => 1],
        ]]])));
        self::assertInstanceOf(SkippedTld::class, $try);
        self::assertSame(['com.tr', 'unsupported_currency', 'TRY'], [$try->name, $try->reason, $try->detail]);

        $twoYears = DomainNameApiTldParser::parse(ExactJson::decode(self::rawItem(['name' => 'ai', 'prices' => [[
            'register' => [['price' => '__REG__', 'currency' => 'USD', 'period' => 3], ['price' => '__REN__', 'currency' => 'USD', 'period' => 2]],
            'renew' => ['price' => '__REN__', 'currency' => 'USD', 'period' => 1],
        ]]])));
        self::assertInstanceOf(SkippedTld::class, $twoYears);
        self::assertSame(['ai', 'no_register_price', 'periods:2'], [$twoYears->name, $twoYears->reason, $twoYears->detail]);

        self::assertSame('no_register_price', DomainNameApiTldParser::item(ExactJson::decode(self::rawItem(['prices' => [['renew' => ['price' => '__REN__', 'currency' => 'USD']]]]))));
    }

    /** @return iterable<string, array{mixed}> */
    public static function unusableOptionalPrices(): iterable
    {
        yield 'zero transfer' => [['price' => 0, 'currency' => 'USD']];
        yield 'negative transfer' => [['price' => -1, 'currency' => 'USD']];
        yield 'text instead of object' => ['free'];
        yield 'unknown currency' => [['price' => '5', 'currency' => 'TRY']];
        yield 'missing currency' => [['price' => '5']];
        yield 'other currency than register' => [['price' => '5', 'currency' => 'EUR']];
        yield 'list of non-objects' => [[1, 2]];
    }

    /** DR-15: an unusable transfer or restore price drops that price, not the extension. */
    #[DataProvider('unusableOptionalPrices')]
    public function testAnUnusableOptionalPriceIsDroppedNotTheExtension(mixed $bad): void
    {
        $item = ExactJson::decode(self::rawItem(['name' => 'co.uk', 'prices' => [[
            'register' => ['price' => '__REG__', 'currency' => 'USD'],
            'renew' => ['price' => '__REN__', 'currency' => 'USD'],
            'transfer' => $bad,
            'restore' => $bad,
        ]]]));

        $cost = DomainNameApiTldParser::parse($item);

        self::assertInstanceOf(TldCost::class, $cost);
        self::assertSame('co.uk', $cost->tld->value);
        self::assertSame([1081, 1125], [$cost->register->minor, $cost->renew->minor]);
        self::assertNull($cost->transfer);
        self::assertNull($cost->restore);
    }

    public function testAReadableRestoreIsKeptWhenOnlyTransferIsUnusable(): void
    {
        $cost = DomainNameApiTldParser::parse(ExactJson::decode(self::rawItem(['prices' => [[
            'register' => ['price' => '__REG__', 'currency' => 'USD'],
            'renew' => ['price' => '__REN__', 'currency' => 'USD'],
            'transfer' => ['price' => 0, 'currency' => 'USD'],
            'restore' => ['price' => '__TRA__', 'currency' => 'USD'],
        ]]])));

        self::assertInstanceOf(TldCost::class, $cost);
        self::assertNull($cost->transfer);
        self::assertSame(1081, $cost->restore?->minor);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function refusalDetails(): iterable
    {
        $prices = static fn (mixed $register, mixed $renew = ['price' => '1', 'currency' => 'USD']): array => ['prices' => [['register' => $register, 'renew' => $renew]]];

        yield 'zero register' => [self::rawItem($prices(['price' => 0, 'currency' => 'USD'])), 'bad_price', 'reg:zero'];
        yield 'zero with decimals' => [self::rawItem($prices(['price' => '0.00', 'currency' => 'USD'])), 'bad_price', 'reg:zero'];
        yield 'negative renew' => [self::rawItem($prices(['price' => '1', 'currency' => 'USD'], ['price' => -2, 'currency' => 'USD'])), 'bad_price', 'renew:negative'];
        yield 'exponent register' => [str_replace('10.8100', '1.081e1', self::rawItem()), 'bad_price', 'reg:format'];
        yield 'price missing' => [self::rawItem($prices(['currency' => 'USD'])), 'bad_price', 'reg:missing'];
        yield 'price as object' => [self::rawItem($prices(['price' => ['amount' => 1], 'currency' => 'USD'])), 'bad_price', 'reg:type'];
        yield 'register as text' => [self::rawItem($prices('free')), 'bad_price', 'reg:type'];
        yield 'register entry not object' => [self::rawItem($prices([1, 2])), 'bad_price', 'reg:entry'];
        yield 'currency missing' => [self::rawItem($prices(['price' => '1'])), 'unsupported_currency', 'currency:missing'];
        yield 'currency as boolean' => [self::rawItem($prices(['price' => '1', 'currency' => true])), 'unsupported_currency', 'currency:type'];
        yield 'currency as ISO number' => [self::rawItem($prices(['price' => '1', 'currency' => 840])), 'unsupported_currency', 'currency:format'];
        yield 'currency symbol' => [self::rawItem($prices(['price' => '1', 'currency' => '$'])), 'unsupported_currency', 'currency:format'];
        yield 'max period zero' => [self::rawItem(['maxRegistrationPeriod' => 0]), 'bad_period', 'min:1_max:0'];
        yield 'min above max' => [self::rawItem(['minRegistrationPeriod' => 5, 'maxRegistrationPeriod' => 2]), 'bad_period', 'min:5_max:2'];
        yield 'period as text' => [self::rawItem(['maxRegistrationPeriod' => 'ten']), 'bad_period', 'min:1_max:x'];
        yield 'period too long' => [self::rawItem(['minRegistrationPeriod' => 100]), 'bad_period', 'min:x_max:10'];
    }

    /** DR-15: every refusal names the field and the kind of failure, within the DR-14 detail pattern. */
    #[DataProvider('refusalDetails')]
    public function testRefusalsNameTheFailingFieldAndKind(string $json, string $reason, string $detail): void
    {
        $skip = DomainNameApiTldParser::parse(ExactJson::decode($json));

        self::assertInstanceOf(SkippedTld::class, $skip);
        self::assertSame([$reason, $detail], [$skip->reason, $skip->detail]);
    }

    public function testHostileNamesAndDetailsAreDroppedNotStored(): void
    {
        $skip = DomainNameApiTldParser::parse(ExactJson::decode(self::rawItem(['name' => '<script>x', 'prices' => [[
            'register' => ['price' => '__REG__', 'currency' => '<b>', 'period' => 1],
        ]]])));

        self::assertInstanceOf(SkippedTld::class, $skip);
        self::assertSame('bad_name', $skip->reason);
        self::assertNull($skip->name);
        self::assertNull((new SkippedTld('com', 'unsupported_currency', 'a"b<c'))->detail);
        self::assertSame('xn--p1ai', (new SkippedTld('.XN--P1AI', 'bad_price'))->name);
    }
}
