<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Provider\Http;

use PaxofiCloud\Integration\Provider\ProviderCredential;

/**
 * The only outbound HTTP path to provider APIs (SRS SEC-012, threat model
 * V-02/V-15, PCF invariant "no uncontrolled external calls").
 *
 * - Only allow-listed https base URLs; IP literals and odd ports are refused.
 * - DNS is resolved here, every address must be public, and curl is pinned to
 *   exactly those addresses (CURLOPT_RESOLVE), so a DNS answer cannot steer a
 *   call to an internal network between the check and the connect.
 * - TLS 1.2+ with peer and host verification; no redirects; https only even
 *   for redirect protocols.
 * - Connect/total timeouts and response body and header caps.
 * - Environment proxies are ignored so the address pinning cannot be bypassed.
 * - Nothing about the request or response is logged here; errors carry curl's
 *   error code and a fixed description, never headers or bodies.
 */
final class ProviderHttpClient implements ProviderTransport
{
    private const string USER_AGENT = 'PaxofiCloud-ProviderClient/1';
    private const int MAX_HEADER_BYTES = 32 * 1024;
    private const array DENIED_RANGES = [
        '100.64.0.0/10', '192.0.0.0/24', '192.0.2.0/24', '198.18.0.0/15',
        '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
        'fc00::/7', 'fe80::/10', 'ff00::/8', '2001:db8::/32', '64:ff9b::/96', '::ffff:0:0/96',
    ];

    /** @var array<string, ProviderEndpoint> */
    private readonly array $endpoints;

    /** @param list<ProviderEndpoint> $endpoints */
    public function __construct(
        array $endpoints,
        private readonly HostResolver $resolver = new SystemHostResolver(),
        private readonly int $connectTimeoutMs = 3_000,
        private readonly int $totalTimeoutMs = 10_000,
        private readonly int $maxResponseBytes = 1_048_576,
    ) {
        if ($endpoints === []) {
            throw new \InvalidArgumentException('At least one provider endpoint must be allow-listed.');
        }
        if ($connectTimeoutMs < 1 || $totalTimeoutMs < $connectTimeoutMs || $totalTimeoutMs > 60_000) {
            throw new \InvalidArgumentException('Timeouts must be positive, total >= connect, and at most 60 s.');
        }
        if ($maxResponseBytes < 1 || $maxResponseBytes > 16 * 1_048_576) {
            throw new \InvalidArgumentException('Response size cap must be between 1 byte and 16 MiB.');
        }

        $byUrl = [];
        foreach ($endpoints as $endpoint) {
            $byUrl[$endpoint->baseUrl] = $endpoint;
        }
        $this->endpoints = $byUrl;
    }

    public function send(string $baseUrl, ProviderRequest $request, ProviderCredential $credential): ProviderResponse
    {
        $endpoint = $this->endpoints[$baseUrl] ?? throw new TransportFailure('Provider base URL is not allow-listed.');
        $addresses = $this->vettedAddresses($endpoint->host);

        $handle = curl_init();
        if ($handle === false) {
            throw new TransportFailure('Could not initialise the HTTP client.');
        }

        $body = '';
        $bodyTooLarge = false;
        $headers = [];
        $headerBytes = 0;

        $options = $this->curlOptions($endpoint, $request, $credential, $addresses);
        $options[CURLOPT_WRITEFUNCTION] = function ($ch, string $chunk) use (&$body, &$bodyTooLarge): int {
            if (strlen($body) + strlen($chunk) > $this->maxResponseBytes) {
                $bodyTooLarge = true;

                return 0; // aborts the transfer
            }
            $body .= $chunk;

            return strlen($chunk);
        };
        $options[CURLOPT_HEADERFUNCTION] = static function ($ch, string $line) use (&$headers, &$headerBytes): int {
            $headerBytes += strlen($line);
            if ($headerBytes > self::MAX_HEADER_BYTES) {
                return 0;
            }
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }

            return strlen($line);
        };

        try {
            if (!curl_setopt_array($handle, $options)) {
                throw new TransportFailure('Could not configure the HTTP client.');
            }
            $ok = curl_exec($handle);
            $errno = curl_errno($handle);
            $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        } finally {
            unset($handle); // curl_close() is a no-op since PHP 8.0 and deprecated in 8.5
        }

        if ($bodyTooLarge) {
            throw new TransportFailure(sprintf('Provider response exceeded %d bytes.', $this->maxResponseBytes));
        }
        if ($ok === false || $errno !== 0) {
            throw new TransportFailure(sprintf('Provider request failed (curl error %d: %s).', $errno, curl_strerror($errno) ?? 'unknown'));
        }
        if ($status < 100 || $status > 599) {
            throw new TransportFailure('Provider returned no valid HTTP status.');
        }

        return new ProviderResponse($status, $headers, $body);
    }

    /**
     * Security-relevant curl options, separated so tests can assert them.
     *
     * @param list<string> $addresses
     * @return array<int, mixed>
     */
    public function curlOptions(ProviderEndpoint $endpoint, ProviderRequest $request, ProviderCredential $credential, array $addresses): array
    {
        $headers = [
            'Authorization: Bearer ' . $credential->reveal(),
            'Accept: application/json',
            'User-Agent: ' . self::USER_AGENT,
        ];
        $options = [
            CURLOPT_URL => $endpoint->url($request->path, $request->query),
            CURLOPT_CUSTOMREQUEST => $request->method,
            CURLOPT_PROTOCOLS_STR => 'https',
            CURLOPT_REDIR_PROTOCOLS_STR => 'https',
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
            CURLOPT_CONNECTTIMEOUT_MS => $this->connectTimeoutMs,
            CURLOPT_TIMEOUT_MS => $this->totalTimeoutMs,
            CURLOPT_RESOLVE => [sprintf('%s:443:%s', $endpoint->host, implode(',', array_map(
                static fn (string $ip): string => str_contains($ip, ':') ? '[' . $ip . ']' : $ip,
                $addresses,
            )))],
            // Never route through an environment proxy: a proxy would bypass the
            // vetted CURLOPT_RESOLVE pinning above.
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_NOSIGNAL => true,
            CURLOPT_ENCODING => '',
        ];

        if ($request->json !== null) {
            $headers[] = 'Content-Type: application/json';
            $options[CURLOPT_POSTFIELDS] = json_encode($request->json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        }
        $options[CURLOPT_HTTPHEADER] = $headers;

        return $options;
    }

    /**
     * Resolves the host and refuses private, loopback, link-local, reserved
     * and otherwise non-public addresses (SSRF, threat model V-15).
     *
     * @return non-empty-list<string>
     */
    public function vettedAddresses(string $host): array
    {
        $addresses = $this->resolver->resolve($host);
        if ($addresses === []) {
            throw new TransportFailure('Provider host did not resolve.');
        }
        foreach ($addresses as $ip) {
            if (!self::isPublicAddress($ip)) {
                throw new TransportFailure('Provider host resolved to a non-public address; call refused.');
            }
        }

        return $addresses;
    }

    public static function isPublicAddress(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        // Non-public ranges PHP's filter lets through (verified on PHP 8.4):
        // CGNAT, IETF protocol assignments, documentation, benchmarking,
        // multicast and future-use space, plus IPv6 equivalents.
        foreach (self::DENIED_RANGES as $cidr) {
            if (self::inCidr($ip, $cidr)) {
                return false;
            }
        }

        return true;
    }

    private static function inCidr(string $ip, string $cidr): bool
    {
        [$network, $bits] = explode('/', $cidr);
        $ipBin = inet_pton($ip);
        $netBin = inet_pton($network);
        if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
            return false;
        }
        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        if (substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
            return false;
        }
        $remainder = $bits % 8;
        if ($remainder === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $remainder)) & 0xFF;

        return (ord($ipBin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask);
    }
}
