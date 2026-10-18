<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Staff\Passkeys;

/** Invitation tokens, ceremony IDs and challenges: 256 bits from the CSPRNG, base64url (§6.17). */
final class PasskeyTokens
{
    public static function newToken(): string
    {
        return self::encode(random_bytes(32));
    }

    public static function isToken(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{43}$/', $value) === 1;
    }

    public static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function decode(string $value): ?string
    {
        if (preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
            return null;
        }
        $bytes = base64_decode(strtr($value, '-_', '+/'), true);

        return $bytes === false ? null : $bytes;
    }

    private function __construct()
    {
    }
}
