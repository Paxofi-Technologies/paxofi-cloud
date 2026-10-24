<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Registrar\Dynadot;

use PaxofiCloud\Application\Contracts\DomainPriceFeed;
use PaxofiCloud\Application\Registrar\RegistrarUnavailable;
use PaxofiCloud\Application\Registrar\RegistrarWriteGuard;
use PaxofiCloud\Domain\Pricing\CostCurrency;
use PaxofiCloud\Domain\Registrar\FeedFieldInventory;
use PaxofiCloud\Domain\Registrar\SkippedTld;
use PaxofiCloud\Domain\Registrar\TldCostList;
use PaxofiCloud\Integration\Provider\Http\ExactJson;
use PaxofiCloud\Integration\Provider\Http\MalformedProviderResponse;
use PaxofiCloud\Integration\Provider\Http\ProviderEndpoint;
use PaxofiCloud\Integration\Provider\Http\ProviderRequest;
use PaxofiCloud\Integration\Provider\Http\ProviderTransport;
use PaxofiCloud\Integration\Provider\Http\TransportFailure;
use PaxofiCloud\Integration\Provider\ProviderCredentialSource;

/**
 * Dynadot RESTful adapter for the domain price list
 * (THREAT-MODEL-DOMAIN-PRICING.md §8, DY-1 to DY-9). Our own client, no SDK,
 * and never the legacy API3, which puts the key in the URL (DY-1).
 *
 * The key goes in the Authorization header and every call is signed with the
 * API secret (DY-3). Dynadot handles one request at a time, so pages are
 * fetched one after another with a pause between them, and a 429 waits and
 * retries a bounded number of times (DY-4). Every call asks the write guard
 * first (DR-4).
 */
final readonly class DynadotClient implements DomainPriceFeed
{
    public const string PROVIDER = 'dynadot';
    public const int MAX_PAGES = 200;
    public const int MAX_ITEMS = 5000;
    public const int MAX_RESPONSE_BYTES = 2 * 1_048_576;
    /** DY-4: at least this long between calls (Dynadot allows about one per second). */
    public const int PAUSE_MS = 1_100;
    /** DY-4: a 429 is retried this many times per page, after these pauses. */
    private const array RATE_LIMIT_WAITS_MS = [2_000, 4_000, 8_000];

    private ProviderEndpoint $endpoint;

    /**
     * @param \Closure(int): void $pause sleeps for the given milliseconds
     * @param \Closure(): string $requestId a fresh UUID per request
     */
    public function __construct(
        private ProviderTransport $transport,
        private ProviderCredentialSource $credentials,
        private string $credentialId,
        private DynadotEnvironment $environment,
        private RegistrarWriteGuard $guard,
        private \Closure $pause,
        private \Closure $requestId,
    ) {
        $this->endpoint = self::endpoint($environment);
    }

    public static function endpoint(DynadotEnvironment $environment): ProviderEndpoint
    {
        return new ProviderEndpoint($environment->baseUrl(), 'Authorization', 'Bearer');
    }

    public function provider(): string
    {
        return self::PROVIDER;
    }

    public function fetchAll(): TldCostList
    {
        $items = [];
        $refused = [];
        $skipped = [];
        $seen = [];
        $names = [];
        $rows = 0;
        $fields = new FeedFieldInventory();
        $query = ['currency' => 'usd'];
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            if ($page > 0) {
                ($this->pause)(self::PAUSE_MS);
            }
            $data = $this->send(DynadotOperation::TldPrice, $query);
            if (strtoupper(is_string($data['currency'] ?? null) ? $data['currency'] : '') !== 'USD') {
                throw new RegistrarUnavailable('malformed');
            }
            $list = $data['tld_price_list'] ?? null;
            if (!is_array($list) || ($list !== [] && !array_is_list($list))) {
                throw new RegistrarUnavailable('malformed');
            }
            $added = 0;
            foreach ($list as $raw) {
                if (++$rows > self::MAX_ITEMS) {
                    throw new RegistrarUnavailable('too_many_pages');
                }
                $fields->add($raw);
                // A page counts as progress when it names any extension not seen before, usable or not.
                $name = is_array($raw) && is_string($raw['tld'] ?? null) ? strtolower(ltrim($raw['tld'], '.')) : '';
                if ($name !== '' && !isset($names[$name])) {
                    $names[$name] = true;
                    $added++;
                }
                $result = DynadotTldParser::parse($raw, CostCurrency::USD);
                if ($result instanceof SkippedTld) {
                    $refused[$result->reason] = ($refused[$result->reason] ?? 0) + 1;
                    $skipped[] = $result;
                    continue;
                }
                if (isset($seen[$result->tld->value])) {
                    $refused['duplicate'] = ($refused['duplicate'] ?? 0) + 1;
                    $skipped[] = new SkippedTld($result->tld->value, 'duplicate');
                    continue;
                }
                $seen[$result->tld->value] = true;
                $items[] = $result;
            }

            // Paging follows what the server says it returned. A page that adds nothing new,
            // a short page, or no paging information at all ends the list.
            $pageSize = self::count($data['page_size'] ?? null);
            if ($pageSize === null || $pageSize < 1 || $added === 0 || count($list) < $pageSize) {
                ksort($refused, SORT_STRING);

                return new TldCostList($items, $refused, $this->environment->value, $skipped, $fields->fields());
            }
            $query = ['currency' => 'usd', 'page_index' => (self::count($data['page'] ?? null) ?? 0) + 1, 'count_per_page' => $pageSize];
        }

        throw new RegistrarUnavailable('too_many_pages');
    }

    /**
     * The single path to the network: guard, credential, signed request,
     * bounded 429 retries. Returns the response's `data` object.
     *
     * @param array<string, string|int> $query
     * @return array<array-key, mixed>
     * @throws RegistrarUnavailable
     */
    public function send(DynadotOperation $operation, array $query = []): array
    {
        [$method, $path, $action] = $operation->spec();
        $this->guard->check($action, self::PROVIDER);

        try {
            $stored = $this->credentials->load($this->credentialId);
        } catch (\RuntimeException) {
            throw new RegistrarUnavailable('credential_missing');
        }
        $credential = DynadotCredential::from($stored);
        // The signed path is exactly the one the transport will request.
        $pathAndQuery = substr($this->endpoint->url($path, $query), strlen('https://' . $this->endpoint->host));

        foreach ([...self::RATE_LIMIT_WAITS_MS, null] as $wait) {
            $requestId = ($this->requestId)();
            try {
                $response = $this->transport->send(
                    $this->environment->baseUrl(),
                    new ProviderRequest($method, $path, $query, null, [
                        'X-Request-ID' => $requestId,
                        'X-Signature' => $credential->sign($pathAndQuery, $requestId),
                    ]),
                    $credential->bearer($this->credentialId),
                );
            } catch (TransportFailure) {
                throw new RegistrarUnavailable('transport');
            }
            if ($response->status === 429 && $wait !== null) {
                ($this->pause)($wait);
                continue;
            }

            return $this->data($response->status, $response->body);
        }

        throw new RegistrarUnavailable('rate_limited');
    }

    /**
     * DY-7: fixed reasons from the HTTP status and the body's own `code`;
     * Dynadot's message text is never kept.
     *
     * @return array<array-key, mixed>
     */
    private function data(int $status, string $body): array
    {
        if (in_array($status, [301, 302, 401, 403], true)) {
            throw new RegistrarUnavailable('unauthorised');
        }
        if ($status === 429) {
            throw new RegistrarUnavailable('rate_limited');
        }
        if ($status < 200 || $status > 299) {
            throw new RegistrarUnavailable('provider_error');
        }
        try {
            $document = ExactJson::decode($body);
        } catch (MalformedProviderResponse) {
            throw new RegistrarUnavailable('malformed');
        }
        $code = $document['code'] ?? null;
        if ($code !== null) {
            if (!is_string($code)) {
                throw new RegistrarUnavailable('malformed');
            }
            if (in_array($code, ['401', '403'], true)) {
                throw new RegistrarUnavailable('unauthorised');
            }
            if ($code === '429') {
                throw new RegistrarUnavailable('rate_limited');
            }
            if ($code !== '200') {
                throw new RegistrarUnavailable('provider_error');
            }
        }
        $data = $document['data'] ?? null;
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new RegistrarUnavailable('malformed');
        }

        return $data;
    }

    private static function count(mixed $value): ?int
    {
        return is_string($value) && preg_match('/^(0|[1-9][0-9]{0,5})$/', $value) === 1 ? (int) $value : null;
    }
}
