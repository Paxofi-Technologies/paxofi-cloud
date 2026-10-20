<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Integration\Fx;

use PaxofiCloud\Application\Pricing\FxSourceUnavailable;
use PaxofiCloud\Domain\Pricing\CostCurrency;
use PaxofiCloud\Integration\Fx\ExchangeRateApiSource;
use PaxofiCloud\Tests\Support\FakeProviderTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** CD-16, C-26, C-27: the free rate source is untrusted input. */
final class ExchangeRateApiSourceTest extends TestCase
{
    private const string BODY = '{"result":"success","provider":"https://www.exchangerate-api.com","documentation":"https://www.exchangerate-api.com/docs/free",'
        . '"terms_of_use":"https://www.exchangerate-api.com/terms","time_last_update_unix":1792454552,"time_last_update_utc":"Tue, 20 Oct 2026 00:02:32 +0000",'
        . '"time_next_update_unix":1792541262,"base_code":"USD","rates":{"USD":1,"AED":3.6725,"EUR":0.891477,"GBP":0.766,"NGN":1332.819531,"ZAR":17.9}}';

    public function testReadsRatesFromTheirDecimalTextWithoutFloats(): void
    {
        $rates = ExchangeRateApiSource::parse(self::BODY);

        self::assertSame('1332.819531', $rates->rate(CostCurrency::NGN)->value);
        self::assertSame('0.891477', $rates->rate(CostCurrency::EUR)->value);
        self::assertSame('1', $rates->rate(CostCurrency::USD)->value);
        self::assertSame(ExchangeRateApiSource::NAME, $rates->source);
        self::assertSame('2026-10-20T00:02:32+00:00', $rates->publishedAt->format(\DATE_ATOM));
    }

    public function testKeepsAtMostTwelveFractionalDigits(): void
    {
        $rates = ExchangeRateApiSource::parse(str_replace('0.891477', '0.89147700000012345678', self::BODY));

        self::assertSame('0.891477', $rates->rate(CostCurrency::EUR)->value);
    }

    /** @return iterable<string, array{string, string}> */
    public static function unusable(): iterable
    {
        yield 'not json' => ['<html>', 'malformed_json'];
        yield 'error result' => [str_replace('"result":"success"', '"result":"error"', self::BODY), 'unexpected_document'];
        yield 'other base' => [str_replace('"base_code":"USD"', '"base_code":"EUR"', self::BODY), 'unexpected_document'];
        yield 'no publish time' => [str_replace('"time_last_update_unix":1792454552,', '', self::BODY), 'missing_publish_time'];
        yield 'missing EUR' => [str_replace('"EUR":0.891477,', '', self::BODY), 'rate_eur_missing'];
        yield 'NGN in exponent form' => [str_replace('1332.819531', '1.332819531e3', self::BODY), 'rate_ngn_not_a_plain_decimal'];
        yield 'negative NGN' => [str_replace('1332.819531', '-1332.8', self::BODY), 'rate_ngn_not_a_plain_decimal'];
        yield 'zero NGN' => [str_replace('1332.819531', '0', self::BODY), 'rate_ngn_out_of_range'];
        yield 'NGN as a string' => [str_replace('1332.819531', '"1332.8"', self::BODY), 'rate_ngn_not_a_plain_decimal'];
        yield 'dollar not 1' => [str_replace('"USD":1,', '"USD":1.2,', self::BODY), 'invalid_rates'];
    }

    #[DataProvider('unusable')]
    public function testRefusesUnusableAnswers(string $body, string $reason): void
    {
        try {
            ExchangeRateApiSource::parse($body);
            self::fail('an unusable answer was accepted');
        } catch (FxSourceUnavailable $e) {
            self::assertSame($reason, $e->getMessage());
        }
    }

    public function testRefusesARepeatedRate(): void
    {
        // json_decode keeps the last duplicate; reading raw text must not pick one silently.
        $this->expectException(FxSourceUnavailable::class);
        $this->expectExceptionMessage('rate_ngn_repeated');
        ExchangeRateApiSource::parse(str_replace('"ZAR":17.9', '"ZAR":17.9,"NGN":1.0', self::BODY));
    }

    public function testFetchesTheUsdEndpointOnly(): void
    {
        $transport = (new FakeProviderTransport())->expectRaw('GET', '/v6/latest/usd', 200, self::BODY);
        $rates = (new ExchangeRateApiSource($transport))->fetch();

        self::assertSame('1332.819531', $rates->rate(CostCurrency::NGN)->value);
        self::assertSame(ExchangeRateApiSource::BASE_URL, $transport->sent[0]['baseUrl']);
    }

    public function testTransportFailuresAndHttpErrorsAreUnavailable(): void
    {
        foreach ([
            [(new FakeProviderTransport())->expectFailure('GET', '/v6/latest/usd'), 'transport'],
            [(new FakeProviderTransport())->expectRaw('GET', '/v6/latest/usd', 503, 'down'), 'http_503'],
        ] as [$transport, $reason]) {
            try {
                (new ExchangeRateApiSource($transport))->fetch();
                self::fail('failure not reported');
            } catch (FxSourceUnavailable $e) {
                self::assertSame($reason, $e->getMessage());
            }
        }
    }

    public function testTheEndpointIsHttpsToTheExpectedHost(): void
    {
        self::assertSame('open.er-api.com', ExchangeRateApiSource::endpoint()->host);
    }
}
