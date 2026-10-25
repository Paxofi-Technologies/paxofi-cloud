<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Rdap;

use PaxofiCloud\Application\Contracts\DomainLookup;
use PaxofiCloud\Integration\Provider\Http\ProviderEndpoint;
use PaxofiCloud\Integration\Provider\Http\ProviderHttpClient;
use PaxofiCloud\Integration\Provider\Http\ProviderRequest;
use PaxofiCloud\Integration\Provider\Http\ProviderTransport;
use PaxofiCloud\Integration\Provider\ProviderCredential;

/**
 * One RDAP domain lookup (THREAT-MODEL-DOMAIN-SEARCH.md DS-3): only the HTTP
 * status is used, 200 taken and 404 available; the body is never parsed.
 * Each call allow-lists exactly the one server for the extension, through
 * ProviderHttpClient (own DNS, public-IP pinning, verified TLS, no redirects).
 * Anything else, or any failure, is `unknown`.
 */
final readonly class RdapLookup implements DomainLookup
{
    public const int MAX_BYTES = 65_536;

    /** @param \Closure(ProviderEndpoint): ProviderTransport|null $transportFor builds the transport for one server; tests inject a fake */
    public function __construct(private ?\Closure $transportFor = null)
    {
    }

    public function check(string $domain, string $server): string
    {
        try {
            $endpoint = new ProviderEndpoint($server, 'X-Client', '', 'application/rdap+json');
            $transport = $this->transportFor !== null
                ? ($this->transportFor)($endpoint)
                : new ProviderHttpClient([$endpoint], connectTimeoutMs: 2_000, totalTimeoutMs: 4_000, maxResponseBytes: self::MAX_BYTES);
            $response = $transport->send($server, ProviderRequest::get('/domain/' . $domain), new ProviderCredential('rdap-client-tag', 'paxoficloud'));
        } catch (\Throwable) {
            return 'unknown';
        }

        return match ($response->status) {
            200 => 'taken',
            404 => 'available',
            default => 'unknown',
        };
    }
}
