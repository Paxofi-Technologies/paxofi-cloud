<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Registrar\DomainNameApi;

use PaxofiCloud\Application\Contracts\AvailabilityChecker;
use PaxofiCloud\Application\Contracts\DomainPriceFeed;
use PaxofiCloud\Application\Registrar\RegistrarUnavailable;
use PaxofiCloud\Application\Registrar\RegistrarWriteGuard;
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
 * DomainNameAPI REST adapter (THREAT-MODEL-DOMAIN-REGISTRAR.md). Our own
 * client rather than the vendor library, which reports request and response
 * data to a third-party tracker (DR-1).
 *
 * Every call goes through send(), which asks the RegistrarWriteGuard first, so
 * no write can reach the network where writes are disabled (DR-4). Responses
 * are decoded with ExactJson so prices stay decimal text (DR-5).
 */
final readonly class DomainNameApiClient implements DomainPriceFeed, AvailabilityChecker
{
    public const string PROVIDER = 'domainnameapi';
    public const int PAGE_SIZE = 100;
    public const int MAX_PAGES = 30;

    public function __construct(
        private ProviderTransport $transport,
        private ProviderCredentialSource $credentials,
        private string $credentialId,
        private DomainNameApiEnvironment $environment,
        private RegistrarWriteGuard $guard,
    ) {
    }

    /** The API key goes in X-API-KEY with no scheme; the Reseller ID is a separate request header. */
    public static function endpoint(DomainNameApiEnvironment $environment): ProviderEndpoint
    {
        return new ProviderEndpoint($environment->baseUrl(), 'X-API-KEY', '');
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
        $fields = new FeedFieldInventory();
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $document = $this->send(DomainNameApiOperation::TldList, ['MaxResultCount' => self::PAGE_SIZE, 'SkipCount' => $page * self::PAGE_SIZE]);
            $list = $document['items'] ?? null;
            if (!is_array($list) || ($list !== [] && !array_is_list($list))) {
                throw new RegistrarUnavailable('malformed');
            }
            foreach ($list as $raw) {
                $fields->add($raw);
                $result = DomainNameApiTldParser::parse($raw);
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
            $total = $document['totalCount'] ?? null;
            $fetched = ($page + 1) * self::PAGE_SIZE;
            if (count($list) < self::PAGE_SIZE || (is_string($total) && ctype_digit($total) && $fetched >= (int) $total)) {
                ksort($refused, SORT_STRING);

                return new TldCostList($items, $refused, $this->environment->value, $skipped, $fields->fields());
            }
        }

        throw new RegistrarUnavailable('too_many_pages');
    }

    /**
     * The registrar's own availability and premium check for up to 6 names
     * (DS-13). A read: allowed on staging by the write guard.
     *
     * @param list<string> $domains
     * @return array<string, \PaxofiCloud\Domain\Registrar\AvailabilityAnswer>
     * @throws RegistrarUnavailable
     */
    public function checkAvailability(array $domains): array
    {
        $domains = array_values(array_unique(array_map('strtolower', $domains)));
        if ($domains === [] || count($domains) > 6) {
            throw new \InvalidArgumentException('Ask about 1 to 6 domains at a time.');
        }
        $body = $this->send(DomainNameApiOperation::BulkSearch, [], array_map(static fn (string $d): array => ['domainName' => $d], $domains));

        $answers = DomainNameApiAvailabilityParser::parse($body, $domains);
        if ($answers === []) {
            // A reply that names none of the domains we asked about is a shape we
            // do not understand; say so instead of answering "unknown" silently.
            throw new RegistrarUnavailable('malformed');
        }

        return $answers;
    }

    /**
     * The single path to the network. The guard runs before the credential is
     * even loaded, so a refused write has no side effect at all.
     *
     * @param array<string, string|int> $query
     * @param array<array-key, mixed>|null $json
     * @return array<array-key, mixed>
     * @throws RegistrarUnavailable
     */
    public function send(DomainNameApiOperation $operation, array $query = [], ?array $json = null): array
    {
        [$method, $path, $action] = $operation->spec();
        $this->guard->check($action, self::PROVIDER);

        try {
            $stored = $this->credentials->load($this->credentialId);
        } catch (\RuntimeException) {
            throw new RegistrarUnavailable('credential_missing');
        }
        $credential = DomainNameApiCredential::from($stored);

        try {
            $response = $this->transport->send(
                $this->environment->baseUrl(),
                new ProviderRequest($method, $path, $query, $json, ['__reseller' => $credential->resellerId]),
                $credential->apiKey,
            );
        } catch (TransportFailure) {
            throw new RegistrarUnavailable('transport');
        }

        $status = $response->status;
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
            return $response->body === '' ? [] : ExactJson::decode($response->body);
        } catch (MalformedProviderResponse) {
            throw new RegistrarUnavailable('malformed');
        }
    }
}
