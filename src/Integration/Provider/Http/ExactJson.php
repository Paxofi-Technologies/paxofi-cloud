<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Provider\Http;

/**
 * Decodes JSON with every number kept as its exact text, so a price such as
 * 10.81 arrives as the string "10.81" and never passes through a float
 * (THREAT-MODEL-DOMAIN-REGISTRAR.md, DR-5).
 *
 * One pass rewrites each number token that sits outside a string into a JSON
 * string; string literals are matched whole first, so digits inside them are
 * untouched. The result is then decoded by PHP's own JSON parser, which still
 * rejects anything that is not valid JSON.
 */
final class ExactJson
{
    private const string TOKENS = '/"(?:[^"\\\\]|\\\\.)*+"|-?(?:0|[1-9][0-9]*+)(?:\.[0-9]++)?(?:[eE][+-]?[0-9]++)?/';

    /**
     * @param int<1, max> $depth
     * @return array<array-key, mixed>
     * @throws MalformedProviderResponse
     */
    public static function decode(string $body, int $depth = 16): array
    {
        $quoted = preg_replace_callback(
            self::TOKENS,
            static fn (array $m): string => $m[0][0] === '"' ? $m[0] : '"' . $m[0] . '"',
            $body,
        );
        if ($quoted === null) {
            throw new MalformedProviderResponse('Provider response could not be scanned.');
        }
        try {
            $decoded = json_decode($quoted, true, $depth, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new MalformedProviderResponse('Provider response is not valid JSON.');
        }
        if (!is_array($decoded)) {
            throw new MalformedProviderResponse('Provider response is not a JSON object or array.');
        }

        return $decoded;
    }
}
