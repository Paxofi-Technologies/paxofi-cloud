<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Provider\Http;

use PaxofiCloud\Integration\Provider\ProviderCredential;

/**
 * Sends one request to a provider API. The production implementation
 * (ProviderHttpClient, SEC-012) enforces https, a per-provider host
 * allowlist, certificate verification, timeouts, no redirects and a
 * response size cap; tests use an in-memory fake.
 *
 * Implementations throw TransportFailure for network-level errors
 * (DNS, connect, TLS, timeout) and return every HTTP response, including
 * 4xx/5xx, for the adapter to classify.
 */
interface ProviderTransport
{
    public function send(string $baseUrl, ProviderRequest $request, ProviderCredential $credential): ProviderResponse;
}
