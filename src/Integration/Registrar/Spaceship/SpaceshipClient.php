<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Registrar\Spaceship;

use PaxofiCloud\Application\Contracts\DomainAvailabilityCheck;
use PaxofiCloud\Application\Registrar\AvailabilityAnswer;
use PaxofiCloud\Application\Registrar\RegistrarUnavailable;
use PaxofiCloud\Application\Registrar\RegistrarWriteGuard;
use PaxofiCloud\Domain\Pricing\CostCurrency;
use PaxofiCloud\Domain\Registrar\CostAmount;
use PaxofiCloud\Integration\Provider\Http\ExactJson;
use PaxofiCloud\Integration\Provider\Http\MalformedProviderResponse;
use PaxofiCloud\Integration\Provider\Http\ProviderEndpoint;
use PaxofiCloud\Integration\Provider\Http\ProviderRequest;
use PaxofiCloud\Integration\Provider\Http\ProviderTransport;
use PaxofiCloud\Integration\Provider\Http\TransportFailure;
use PaxofiCloud\Integration\Provider\ProviderCredentialSource;

/**
 * Spaceship availability adapter (THREAT-MODEL-DOMAIN-PRICING.md §8.3, SP-1
 * to SP-7). Our own client, no SDK. It has one operation, a read: the bulk
 * availability check, which is the only place Spaceship's API shows a price.
 *
 * The secret goes in the endpoint's `X-API-Secret` header and the key in
 * `X-API-Key`; neither is ever in a URL, a log or an error (SP-2). The call
 * asks the write guard first (DR-4). A 429 is not retried: a check is one
 * request, and the admin console limits how often one may be asked for.
 */
final readonly class SpaceshipClient implements DomainAvailabilityCheck
{
    public const string PROVIDER = 'spaceship';
    public const string BASE_URL = 'https://spaceship.dev/api/v1';
    public const int MAX_RESPONSE_BYTES = 262_144;
    private const string NAME = '/^(?=.{4,255}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/';

    public function __construct(
        private ProviderTransport $transport,
        private ProviderCredentialSource $credentials,
        private string $credentialId,
        private RegistrarWriteGuard $guard,
    ) {
    }

    public static function endpoint(): ProviderEndpoint
    {
        return new ProviderEndpoint(self::BASE_URL, 'X-API-Secret', '');
    }

    public function provider(): string
    {
        return self::PROVIDER;
    }

    public function check(array $names): array
    {
        if ($names === [] || count($names) > self::MAX_NAMES || count(array_unique($names)) !== count($names)) {
            throw new \InvalidArgumentException('Between 1 and 20 different names per availability check.');
        }
        foreach ($names as $name) {
            if (preg_match(self::NAME, $name) !== 1) {
                throw new \InvalidArgumentException('Availability names must be lower-case ASCII domain names.');
            }
        }

        [$method, $path, $action] = SpaceshipOperation::Availability->spec();
        $this->guard->check($action, self::PROVIDER);
        try {
            $stored = $this->credentials->load($this->credentialId);
        } catch (\RuntimeException) {
            throw new RegistrarUnavailable('credential_missing');
        }
        $credential = SpaceshipCredential::from($stored);

        try {
            $response = $this->transport->send(
                self::BASE_URL,
                new ProviderRequest($method, $path, [], ['domains' => $names], $credential->keyHeader()),
                $credential->secret($this->credentialId),
            );
        } catch (TransportFailure) {
            throw new RegistrarUnavailable('transport');
        }

        return self::answers($response->status, $response->body, $names);
    }

    /**
     * Fixed reasons from the HTTP status; Spaceship's own text is never kept.
     * Answers for names we did not ask about, or repeated answers, are dropped.
     *
     * @param list<string> $asked
     * @return list<AvailabilityAnswer>
     */
    private static function answers(int $status, string $body, array $asked): array
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
            $document = ExactJson::decode($body, 8);
        } catch (MalformedProviderResponse) {
            throw new RegistrarUnavailable('malformed');
        }
        $list = $document['domains'] ?? null;
        if (!is_array($list) || !array_is_list($list)) {
            throw new RegistrarUnavailable('malformed');
        }

        $wanted = array_fill_keys($asked, true);
        $answers = [];
        foreach ($list as $raw) {
            $domain = is_array($raw) && is_string($raw['domain'] ?? null) ? strtolower($raw['domain']) : null;
            if ($domain === null || !isset($wanted[$domain])) {
                continue;
            }
            unset($wanted[$domain]);
            $answers[] = self::answer($domain, $raw);
        }

        return $answers;
    }

    /** @param array<array-key, mixed> $raw */
    private static function answer(string $domain, array $raw): AvailabilityAnswer
    {
        $result = $raw['result'] ?? null;
        $result = is_string($result) && preg_match(AvailabilityAnswer::WORD, $result) === 1 ? $result : 'other';

        $register = null;
        $other = [];
        $pricing = $raw['premiumPricing'] ?? null;
        foreach (is_array($pricing) && array_is_list($pricing) ? $pricing : [] as $entry) {
            if (!is_array($entry) || !is_string($entry['operation'] ?? null) || !is_string($entry['price'] ?? null)) {
                continue;
            }
            $operation = strtolower($entry['operation']);
            if (preg_match(AvailabilityAnswer::WORD, $operation) !== 1) {
                continue;
            }
            try {
                // A zero, negative or unreadable price is no price.
                $price = CostAmount::fromDecimalText(CostCurrency::USD, $entry['price']);
            } catch (\InvalidArgumentException) {
                continue;
            }
            $currency = is_string($entry['currency'] ?? null) ? strtoupper($entry['currency']) : '';
            if ($operation === 'register') {
                if ($currency === 'USD' && $register === null) {
                    $register = $price;
                }
                continue;
            }
            if (count($other) < AvailabilityAnswer::MAX_OTHER_OPERATIONS) {
                $other[$operation] = true;
            }
        }
        $other = array_keys($other);
        sort($other, SORT_STRING);

        return new AvailabilityAnswer($domain, $result, $register, $other);
    }
}
