<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Registrar\Namecheap;

use PaxofiCloud\Application\Contracts\DomainPriceFeed;
use PaxofiCloud\Application\Registrar\RegistrarUnavailable;
use PaxofiCloud\Application\Registrar\RegistrarWriteGuard;
use PaxofiCloud\Domain\Registrar\CostAmount;
use PaxofiCloud\Domain\Registrar\FeedFieldInventory;
use PaxofiCloud\Domain\Registrar\SkippedTld;
use PaxofiCloud\Domain\Registrar\TldCost;
use PaxofiCloud\Domain\Registrar\TldCostList;
use PaxofiCloud\Domain\Registrar\TldName;
use PaxofiCloud\Integration\Provider\Http\MalformedProviderResponse;
use PaxofiCloud\Integration\Provider\Http\ProviderEndpoint;
use PaxofiCloud\Integration\Provider\Http\ProviderRequest;
use PaxofiCloud\Integration\Provider\Http\ProviderTransport;
use PaxofiCloud\Integration\Provider\Http\TransportFailure;
use PaxofiCloud\Integration\Provider\ProviderCredential;
use PaxofiCloud\Integration\Provider\ProviderCredentialSource;

/**
 * Namecheap XML API adapter for the domain price list
 * (THREAT-MODEL-DOMAIN-PRICING.md NC-1 to NC-9). Our own client, no SDK.
 *
 * The price sheet is fetched as four calls, one per action (REGISTER, RENEW,
 * TRANSFER, REACTIVATE), each parsed as a stream. Register and renew are
 * required for an extension; transfer and reactivate (restore) are optional
 * (DR-15). The credential travels only in the POST body (NC-3), and every
 * call asks the write guard first (DR-4).
 */
final readonly class NamecheapClient implements DomainPriceFeed
{
    public const string PROVIDER = 'namecheap';
    public const string PATH = '/xml.response';
    /** NC-5: the full price sheet is large; this cap applies to this endpoint only. */
    public const int MAX_RESPONSE_BYTES = 16 * 1_048_576;
    private const array ACTIONS = ['register' => 'REGISTER', 'renew' => 'RENEW', 'transfer' => 'TRANSFER', 'restore' => 'REACTIVATE'];
    /** The transport's credential header carries a plain client tag; the real credential is in the form. */
    private const string CLIENT_TAG = 'paxoficloud';

    public function __construct(
        private ProviderTransport $transport,
        private ProviderCredentialSource $credentials,
        private string $credentialId,
        private NamecheapEnvironment $environment,
        private RegistrarWriteGuard $guard,
    ) {
    }

    public static function endpoint(NamecheapEnvironment $environment): ProviderEndpoint
    {
        return new ProviderEndpoint($environment->baseUrl(), 'X-Client', '', 'application/xml');
    }

    public function provider(): string
    {
        return self::PROVIDER;
    }

    public function fetchAll(): TldCostList
    {
        $sheets = [];
        foreach (self::ACTIONS as $type => $action) {
            $sheets[$type] = $this->send(NamecheapOperation::GetPricing, ['ProductType' => 'DOMAIN', 'ActionName' => $action], $action);
        }

        $items = [];
        $refused = [];
        $skipped = [];
        $seen = [];
        $fields = new FeedFieldInventory();
        foreach ($sheets['register']->products as $name => $registerTiers) {
            $raw = ['name' => $name, 'prices' => [[]]];
            foreach (self::ACTIONS as $type => $action) {
                $tier = self::oneYear($sheets[$type]->products[$name] ?? []);
                if ($tier !== null) {
                    $raw['prices'][0][$type] = $tier->attributes;
                }
            }
            $fields->add($raw);

            $result = self::item((string) $name, $registerTiers, $sheets);
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
        ksort($refused, SORT_STRING);

        return new TldCostList($items, $refused, $this->environment->value, $skipped, $fields->fields());
    }

    /**
     * @param list<NamecheapTier> $registerTiers
     * @param array<string, NamecheapPriceSheet> $sheets
     */
    private static function item(string $name, array $registerTiers, array $sheets): TldCost|SkippedTld
    {
        $skip = static fn (string $reason, ?string $detail = null): SkippedTld => new SkippedTld($name, $reason, $detail);
        try {
            $tld = TldName::fromFeed($name);
        } catch (\InvalidArgumentException) {
            return $skip('bad_name');
        }
        $years = array_map(static fn (NamecheapTier $t): int => $t->years, $registerTiers);
        if ($years === []) {
            return $skip('no_register_price');
        }

        $required = [];
        foreach (['register' => 'reg', 'renew' => 'renew'] as $type => $short) {
            $tiers = $type === 'register' ? $registerTiers : ($sheets[$type]->products[$name] ?? []);
            $tier = self::oneYear($tiers);
            if ($tier === null) {
                $shortest = $tiers === [] ? null : min(array_map(static fn (NamecheapTier $t): int => $t->years, $tiers));

                return $skip('no_' . $type . '_price', $shortest === null ? null : 'periods:' . $shortest);
            }
            if (is_string($tier->cost)) {
                return $tier->cost === 'unsupported_currency'
                    ? $skip('unsupported_currency', $tier->currencyDetail)
                    : $skip('bad_price', $short . ':' . $tier->cost);
            }
            $required[$type] = $tier;
        }
        $register = $required['register']->cost;
        $renew = $required['renew']->cost;
        \assert($register instanceof CostAmount && $renew instanceof CostAmount);
        if ($renew->currency !== $register->currency) {
            return $skip('mixed_currency');
        }

        // DR-15: an optional price that cannot be used is dropped on its own.
        $optional = [];
        foreach (['transfer', 'restore'] as $type) {
            $cost = self::oneYear($sheets[$type]->products[$name] ?? [])?->cost;
            $optional[$type] = $cost instanceof CostAmount && $cost->currency === $register->currency ? $cost : null;
        }

        // NC-6: a promotional one-year registration price is flagged for staff.
        $status = $required['register']->promotion ? 'promo' : 'active';

        return new TldCost($tld, $register, $renew, $optional['transfer'], $optional['restore'], min($years), min(max($years), 10), $status);
    }

    /** @param list<NamecheapTier> $tiers */
    private static function oneYear(array $tiers): ?NamecheapTier
    {
        foreach ($tiers as $tier) {
            if ($tier->years === 1) {
                return $tier;
            }
        }

        return null;
    }

    /**
     * The single path to the network. The guard runs before the credential is
     * loaded, so a refused write has no side effect at all.
     *
     * @param array<string, string> $parameters
     * @throws RegistrarUnavailable
     */
    public function send(NamecheapOperation $operation, array $parameters, string $category): NamecheapPriceSheet
    {
        $this->guard->check($operation->action(), self::PROVIDER);

        try {
            $stored = $this->credentials->load($this->credentialId);
        } catch (\RuntimeException) {
            throw new RegistrarUnavailable('credential_missing');
        }
        $credential = NamecheapCredential::from($stored);

        try {
            $response = $this->transport->send(
                $this->environment->baseUrl(),
                ProviderRequest::postForm(self::PATH, $credential->formFields() + ['Command' => $operation->value] + $parameters),
                new ProviderCredential('namecheap-client-tag', self::CLIENT_TAG),
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
            $sheet = NamecheapPriceSheet::parse($response->body, $category);
        } catch (MalformedProviderResponse) {
            throw new RegistrarUnavailable('malformed');
        }
        if ($sheet->errors !== []) {
            throw new RegistrarUnavailable(self::errorReason($sheet->errors));
        }

        return $sheet;
    }

    /**
     * NC-8: fixed reasons from error numbers, never the message text. Numbers
     * starting 1010/1011 are global-parameter errors (user, key, client IP not
     * whitelisted), so the run stops and staff are told to check the
     * connection; anything else is a provider error.
     *
     * @param list<string> $errors
     */
    public static function errorReason(array $errors): string
    {
        foreach ($errors as $number) {
            if (preg_match('/^101[01][0-9]{3}$/', $number) === 1) {
                return 'unauthorised';
            }
        }

        return 'provider_error';
    }
}
