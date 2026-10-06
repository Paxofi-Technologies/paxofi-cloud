<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Provider\Http;

/**
 * An outbound provider API call relative to the adapter's fixed base URL.
 * Paths are built by adapters from validated values only; a path can never
 * carry a scheme, host, query string or traversal (SEC-012).
 */
final class ProviderRequest
{
    private const array METHODS = ['GET', 'POST', 'PUT', 'DELETE'];

    /**
     * @param array<string, string|int> $query
     * @param array<string, mixed>|null $json
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly ?array $json = null,
    ) {
        if (!in_array($method, self::METHODS, true)) {
            throw new \InvalidArgumentException('Unsupported HTTP method for provider calls.');
        }
        if (preg_match('#^/[a-z0-9_-]+(/[a-z0-9_-]+)*$#', $path) !== 1) {
            throw new \InvalidArgumentException('Provider request path must be a plain relative path.');
        }
        if ($json !== null && ($method === 'GET' || $method === 'DELETE')) {
            throw new \InvalidArgumentException('GET and DELETE provider requests must not carry a body.');
        }
    }

    /** @param array<string, string|int> $query */
    public static function get(string $path, array $query = []): self
    {
        return new self('GET', $path, $query);
    }

    /** @param array<string, mixed> $json */
    public static function post(string $path, array $json): self
    {
        return new self('POST', $path, [], $json);
    }

    public static function delete(string $path): self
    {
        return new self('DELETE', $path);
    }
}
