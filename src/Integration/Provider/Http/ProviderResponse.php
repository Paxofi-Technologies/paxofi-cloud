<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Provider\Http;

final class ProviderResponse
{
    /** @var array<string, string> */
    public readonly array $headers;

    /** @param array<string, string> $headers */
    public function __construct(
        public readonly int $status,
        array $headers,
        public readonly string $body,
    ) {
        $normalised = [];
        foreach ($headers as $name => $value) {
            $normalised[strtolower($name)] = $value;
        }
        $this->headers = $normalised;
    }

    public function isSuccess(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * Decodes a JSON object body. Anything else is treated as a malformed
     * provider response, never silently as an empty result.
     *
     * @return array<string, mixed>
     */
    public function json(): array
    {
        if ($this->body === '') {
            return [];
        }

        try {
            $decoded = json_decode($this->body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new MalformedProviderResponse('Provider returned invalid JSON.', 0, $e);
        }
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new MalformedProviderResponse('Provider returned JSON that is not an object.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
