<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Rdap;

use PaxofiCloud\Integration\Provider\Http\ProviderEndpoint;
use PaxofiCloud\Integration\Provider\Http\ProviderRequest;
use PaxofiCloud\Integration\Provider\Http\ProviderTransport;
use PaxofiCloud\Integration\Provider\Http\TransportFailure;
use PaxofiCloud\Integration\Provider\ProviderCredential;

/**
 * Fetches and validates IANA's RDAP bootstrap file for domains (RFC 9224;
 * THREAT-MODEL-DOMAIN-SEARCH.md DS-2). Only entries whose first server is an
 * https URL that ProviderEndpoint accepts (DNS host, no port, credentials,
 * query or odd path) are kept; everything else is dropped, never trusted.
 */
final readonly class RdapBootstrap
{
    public const string BASE_URL = 'https://data.iana.org/rdap';
    public const int MAX_BYTES = 1_048_576;
    private const int MAX_TLDS = 5_000;

    public function __construct(private ProviderTransport $transport)
    {
    }

    public static function endpoint(): ProviderEndpoint
    {
        return new ProviderEndpoint(self::BASE_URL, 'X-Client', '', 'application/json');
    }

    /** @return array<string, string>|null extension => server base URL (no trailing slash); null when the fetch fails */
    public function fetch(): ?array
    {
        try {
            $response = $this->transport->send(self::BASE_URL, ProviderRequest::get('/dns.json'), new ProviderCredential('rdap-client-tag', 'paxoficloud'));
        } catch (TransportFailure) {
            return null;
        }
        if ($response->status !== 200) {
            return null;
        }

        return self::parse($response->body);
    }

    /** @return array<string, string>|null */
    public static function parse(string $body): ?array
    {
        try {
            $document = json_decode($body, true, 6, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        $services = is_array($document) ? ($document['services'] ?? null) : null;
        if (!is_array($services)) {
            return null;
        }
        $map = [];
        foreach ($services as $service) {
            if (!is_array($service) || !is_array($service[0] ?? null) || !is_array($service[1] ?? null)) {
                continue;
            }
            $server = self::server($service[1]);
            if ($server === null) {
                continue;
            }
            foreach ($service[0] as $tld) {
                if (is_string($tld) && preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $tld) === 1 && count($map) < self::MAX_TLDS) {
                    $map[$tld] = $server;
                }
            }
        }

        return $map === [] ? null : $map;
    }

    /** @param array<array-key, mixed> $urls the first https URL that is safe to call, without its trailing slash */
    private static function server(array $urls): ?string
    {
        foreach ($urls as $url) {
            if (!is_string($url) || !str_starts_with($url, 'https://') || strlen($url) > 200) {
                continue;
            }
            $url = rtrim($url, '/');
            try {
                new ProviderEndpoint($url, 'X-Client', '', 'application/rdap+json');
            } catch (\InvalidArgumentException) {
                continue;
            }

            return $url;
        }

        return null;
    }
}
