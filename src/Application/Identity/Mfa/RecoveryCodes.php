<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Identity\Mfa;

/**
 * Recovery codes (threat model D-1, D-5): 10 codes of 80 random bits written
 * as XXXX-XXXX-XXXX-XXXX in base32; only a keyed BLAKE2b hash is stored.
 */
final readonly class RecoveryCodes
{
    public const int COUNT = 10;

    public function __construct(#[\SensitiveParameter] private string $key)
    {
        if (strlen($key) !== SODIUM_CRYPTO_GENERICHASH_KEYBYTES) {
            throw new \InvalidArgumentException('MFA_RECOVERY_KEY must decode to exactly 32 bytes.');
        }
    }

    /** @return list<string> */
    public static function generate(): array
    {
        $codes = [];
        for ($i = 0; $i < self::COUNT; $i++) {
            $codes[] = implode('-', str_split(Base32::encode(random_bytes(10)), 4));
        }

        return $codes;
    }

    /** Case and dashes/spaces do not matter when a customer types a code. */
    public static function normalise(#[\SensitiveParameter] string $code): ?string
    {
        $plain = strtoupper((string) preg_replace('/[\s-]/', '', $code));

        return preg_match('/^[A-Z2-7]{16}$/', $plain) === 1 ? $plain : null;
    }

    public function hash(#[\SensitiveParameter] string $code): ?string
    {
        $plain = self::normalise($code);

        return $plain === null ? null : sodium_bin2hex(sodium_crypto_generichash($plain, $this->key, 32));
    }
}
