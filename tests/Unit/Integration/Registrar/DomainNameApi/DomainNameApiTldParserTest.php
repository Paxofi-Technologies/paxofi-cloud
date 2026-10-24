<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Integration\Registrar\DomainNameApi;

use PaxofiCloud\Domain\Pricing\CostCurrency;
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
        yield 'missing currency' => [self::rawItem(['prices' => [['register' => ['price' => '1'], 'renew' => ['price' => '1', 'currency' => 'USD']]]]), 'unsupported_currency'];
        yield 'bad optional transfer price' => [self::rawItem(['prices' => [['register' => ['price' => '1', 'currency' => 'USD'], 'renew' => ['price' => '1', 'currency' => 'USD'], 'transfer' => 'free']]]), 'bad_price'];
    }

    #[DataProvider('refusals')]
    public function testRefusesBadItemsWithAFixedReason(string $json, string $reason): void
    {
        self::assertSame($reason, DomainNameApiTldParser::item(ExactJson::decode($json)));
        self::assertContains($reason, DomainNameApiTldParser::REASONS);
    }
}
