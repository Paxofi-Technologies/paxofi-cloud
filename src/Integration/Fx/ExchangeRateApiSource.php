<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Fx;

use PaxofiCloud\Application\Contracts\FxRateSource;
use PaxofiCloud\Application\Pricing\FxSourceUnavailable;
use PaxofiCloud\Domain\Pricing\CostCurrency;
use PaxofiCloud\Domain\Pricing\DecimalRate;
use PaxofiCloud\Domain\Pricing\ExchangeRates;
use PaxofiCloud\Integration\Provider\Http\ProviderEndpoint;
use PaxofiCloud\Integration\Provider\Http\ProviderRequest;
use PaxofiCloud\Integration\Provider\Http\ProviderTransport;
use PaxofiCloud\Integration\Provider\Http\TransportFailure;
use PaxofiCloud\Integration\Provider\ProviderCredential;

/**
 * ExchangeRate-API open access endpoint (CEO decision 20 Oct 2026; CD-16):
 * free, no key, updated daily, attribution required. Rates against USD.
 *
 * JSON numbers would decode to floats, so the structure is validated with
 * json_decode but each rate is read from its raw decimal text; a rate given
 * twice, in exponent form or missing is refused.
 */
final readonly class ExchangeRateApiSource implements FxRateSource
{
    public const string BASE_URL = 'https://open.er-api.com';
    public const string NAME = 'exchangerate-api-open';
    // The provider accepts the base code in lower case; ProviderRequest allows lower-case paths only.
    private const string PATH = '/v6/latest/usd';

    public function __construct(private ProviderTransport $transport)
    {
    }

    /** No key is needed; the transport's credential header carries a plain client tag instead. */
    public static function endpoint(): ProviderEndpoint
    {
        return new ProviderEndpoint(self::BASE_URL, 'X-Client', '');
    }

    public function fetch(): ExchangeRates
    {
        try {
            $response = $this->transport->send(self::BASE_URL, ProviderRequest::get(self::PATH), new ProviderCredential('fx-client-tag', 'paxoficloud'));
        } catch (TransportFailure) {
            throw new FxSourceUnavailable('transport');
        }
        if ($response->status !== 200) {
            throw new FxSourceUnavailable('http_' . $response->status);
        }

        return self::parse($response->body);
    }

    /** @throws FxSourceUnavailable */
    public static function parse(string $body): ExchangeRates
    {
        try {
            $document = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new FxSourceUnavailable('malformed_json');
        }
        if (!is_array($document) || ($document['result'] ?? null) !== 'success' || ($document['base_code'] ?? null) !== 'USD') {
            throw new FxSourceUnavailable('unexpected_document');
        }
        $published = $document['time_last_update_unix'] ?? null;
        if (!is_int($published) || $published <= 0) {
            throw new FxSourceUnavailable('missing_publish_time');
        }
        if (!is_array($document['rates'] ?? null)) {
            throw new FxSourceUnavailable('missing_rates');
        }

        $ratesText = self::ratesObjectText($body);
        $perUsd = [];
        foreach (CostCurrency::cases() as $currency) {
            $perUsd[$currency->value] = self::rate($ratesText, $currency->value);
        }

        try {
            return new ExchangeRates($perUsd, self::NAME, (new \DateTimeImmutable('@' . $published))->setTimezone(new \DateTimeZone('UTC')));
        } catch (\InvalidArgumentException) {
            throw new FxSourceUnavailable('invalid_rates');
        }
    }

    /** The text of the flat "rates" object: it holds only code → number pairs. */
    private static function ratesObjectText(string $body): string
    {
        if (preg_match_all('/"rates"\s*:\s*\{([^{}]*)\}/', $body, $matches) !== 1) {
            throw new FxSourceUnavailable('missing_rates');
        }

        return $matches[1][0];
    }

    private static function rate(string $ratesText, string $code): DecimalRate
    {
        $count = preg_match_all('/"' . $code . '"\s*:\s*([^,\s}]+)/', $ratesText, $matches);
        if ($count !== 1) {
            throw new FxSourceUnavailable('rate_' . strtolower($code) . '_' . ($count === 0 ? 'missing' : 'repeated'));
        }
        $raw = $matches[1][0];
        if (preg_match('/^[0-9]{1,9}(\.[0-9]+)?$/', $raw) !== 1 || !is_numeric($raw)) {
            throw new FxSourceUnavailable('rate_' . strtolower($code) . '_not_a_plain_decimal');
        }
        // Keep at most 12 fractional digits (truncation is far below a kobo) and one spelling per value.
        $normalised = bcadd($raw, '0', 12);
        $normalised = str_contains($normalised, '.') ? rtrim(rtrim($normalised, '0'), '.') : $normalised;

        try {
            return new DecimalRate($normalised);
        } catch (\InvalidArgumentException) {
            throw new FxSourceUnavailable('rate_' . strtolower($code) . '_out_of_range');
        }
    }
}
