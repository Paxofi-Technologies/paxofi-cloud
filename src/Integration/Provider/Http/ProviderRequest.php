<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Provider\Http;

/**
 * An outbound provider API call relative to the adapter's fixed base URL.
 * Paths are built by adapters from validated values only; a path can never
 * carry a scheme, host, query string or traversal (SEC-012).
 *
 * A POST may carry a JSON body or a form body, never both. A form body can
 * hold credentials (Namecheap takes them as form fields, NC-3), so it is
 * redacted from debug output and a request can never be serialised.
 */
final class ProviderRequest
{
    private const array METHODS = ['GET', 'POST', 'PUT', 'DELETE'];
    /**
     * Extra request headers an adapter may set; anything else is refused.
     * `__reseller` carries the DomainNameAPI Reseller ID (DR-3).
     */
    private const array HEADERS = ['Idempotency-Key', '__reseller'];

    /**
     * @param array<string, string|int> $query
     * @param array<string, mixed>|null $json
     * @param array<string, string> $headers
     * @param array<string, string>|null $form
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly ?array $json = null,
        public readonly array $headers = [],
        #[\SensitiveParameter] public readonly ?array $form = null,
    ) {
        foreach ($headers as $name => $value) {
            if (!in_array($name, self::HEADERS, true) || preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $value) !== 1) {
                throw new \InvalidArgumentException('Provider request header is not allowed or has an invalid value.');
            }
        }
        if (!in_array($method, self::METHODS, true)) {
            throw new \InvalidArgumentException('Unsupported HTTP method for provider calls.');
        }
        // Segments are lower-case words, optionally dotted ("xml.response"); a segment never starts with a dot.
        if (preg_match('#^(/[a-z0-9_-]+(\.[a-z0-9_-]+)*)+$#', $path) !== 1) {
            throw new \InvalidArgumentException('Provider request path must be a plain relative path.');
        }
        if (($json !== null || $form !== null) && ($method === 'GET' || $method === 'DELETE')) {
            throw new \InvalidArgumentException('GET and DELETE provider requests must not carry a body.');
        }
        if ($form !== null) {
            if ($json !== null || $form === []) {
                throw new \InvalidArgumentException('A provider request carries a JSON body or a non-empty form body, not both.');
            }
            foreach ($form as $name => $value) {
                if (preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/', (string) $name) !== 1
                    || strlen($value) > 1024 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                    throw new \InvalidArgumentException('Provider form fields must be plain names with short single-line values.');
                }
            }
        }
    }

    /** @param array<string, string> $form */
    public static function postForm(string $path, #[\SensitiveParameter] array $form): self
    {
        return new self('POST', $path, [], null, [], $form);
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'method' => $this->method,
            'path' => $this->path,
            'query' => $this->query,
            'json' => $this->json === null ? null : '[omitted]',
            'headers' => array_keys($this->headers),
            'form' => $this->form === null ? null : array_fill_keys(array_keys($this->form), '[redacted]'),
        ];
    }

    public function __serialize(): array
    {
        throw new \LogicException('Provider requests must not be serialised.');
    }

    /** @param array<string, string|int> $query */
    public static function get(string $path, array $query = []): self
    {
        return new self('GET', $path, $query);
    }

    /**
     * @param array<string, mixed> $json
     * @param array<string, string> $headers
     */
    public static function post(string $path, array $json, array $headers = []): self
    {
        return new self('POST', $path, [], $json, $headers);
    }

    public static function delete(string $path): self
    {
        return new self('DELETE', $path);
    }
}
