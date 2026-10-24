<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Registrar\Dynadot;

/**
 * Dynadot's request signature (THREAT-MODEL-DOMAIN-PRICING.md DY-3, from
 * https://www.dynadot.com/domain/api-document): Base64 of HMAC-SHA256 over
 * apiKey, the full path and query, the request ID and the body, joined by
 * newlines, keyed with the API secret. Native primitives only.
 */
final class DynadotSignature
{
    public static function compute(
        #[\SensitiveParameter] string $apiKey,
        #[\SensitiveParameter] string $apiSecret,
        string $pathAndQuery,
        string $requestId,
        string $body = '',
    ): string {
        return base64_encode(hash_hmac('sha256', $apiKey . "\n" . $pathAndQuery . "\n" . $requestId . "\n" . $body, $apiSecret, true));
    }

    /** A random version-4 UUID for X-Request-ID. */
    public static function requestId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
