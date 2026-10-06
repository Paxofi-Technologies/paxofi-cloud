<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Provider\Http;

/**
 * One allow-listed provider API base URL (SEC-012). Only https, a DNS
 * hostname (never an IP literal), the default port and a plain path prefix
 * are accepted, so a misconfigured base URL cannot point workers at an
 * internal address or a non-TLS endpoint.
 */
final class ProviderEndpoint
{
    public readonly string $host;
    public readonly string $pathPrefix;

    public function __construct(public readonly string $baseUrl)
    {
        $parts = parse_url($baseUrl);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || !isset($parts['host'])) {
            throw new \InvalidArgumentException('Provider base URL must be an https URL with a host.');
        }
        if (isset($parts['port']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException('Provider base URL must not contain a port, credentials, query or fragment.');
        }
        $host = strtolower($parts['host']);
        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false
            || preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host) !== 1) {
            throw new \InvalidArgumentException('Provider base URL host must be a public DNS name, not an IP address.');
        }
        $path = $parts['path'] ?? '';
        if ($path !== '' && preg_match('#^(/[a-z0-9_-]+)+$#', $path) !== 1) {
            throw new \InvalidArgumentException('Provider base URL path must be a plain prefix such as /v1.');
        }

        $this->host = $host;
        $this->pathPrefix = $path;
    }

    /** @param array<string, string|int> $query */
    public function url(string $path, array $query): string
    {
        $url = 'https://' . $this->host . $this->pathPrefix . $path;

        return $query === [] ? $url : $url . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
}
