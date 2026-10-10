<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Secrets;

/**
 * Authenticated encryption for secrets at rest using libsodium's
 * XChaCha20-Poly1305 (IETF) with a random 192-bit nonce per message
 * (SRS SEC-004; company rule: no home-made cryptography).
 *
 * Envelope format (ASCII, safe for VARCHAR columns):
 *   v1.<keyId>.<base64url(nonce || ciphertext+tag)>
 *
 * The associated data binds a ciphertext to the record it belongs to, so a
 * ciphertext copied into another row (for example from a read-only token to
 * a read/write one) fails to decrypt (threat model V-01).
 */
final class SecretCipher
{
    private const string VERSION = 'v1';

    public function __construct(private readonly KeyRing $keys)
    {
    }

    public function encrypt(#[\SensitiveParameter] string $plaintext, string $associatedData): string
    {
        if ($plaintext === '') {
            throw new \InvalidArgumentException('Refusing to encrypt an empty secret.');
        }
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $associatedData, $nonce, $this->keys->activeKey());

        return implode('.', [self::VERSION, $this->keys->activeKeyId, sodium_bin2base64($nonce . $ciphertext, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING)]);
    }

    public function decrypt(string $envelope, string $associatedData): string
    {
        [$keyId, $payload] = self::parse($envelope);
        $key = $this->keys->key($keyId) ?? throw new DecryptionFailed(sprintf('Unknown key ID "%s".', $keyId));

        $nonceBytes = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        if (strlen($payload) <= $nonceBytes + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES) {
            throw new DecryptionFailed('Envelope payload is too short.');
        }
        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($payload, $nonceBytes), $associatedData, substr($payload, 0, $nonceBytes), $key);
        if ($plaintext === false) {
            throw new DecryptionFailed('Secret failed authentication (tampered, wrong key or wrong record).');
        }

        return $plaintext;
    }

    /** True when the envelope was not produced with the currently active key. */
    public function needsReencryption(string $envelope): bool
    {
        return $this->isOnRetiredKey(self::keyIdOf($envelope));
    }

    /** True when ciphertexts under this key ID should be re-encrypted with the active key. */
    public function isOnRetiredKey(string $keyId): bool
    {
        return $keyId !== $this->keys->activeKeyId;
    }

    public static function keyIdOf(string $envelope): string
    {
        return self::parse($envelope)[0];
    }

    /** @return array{string, string} key ID and decoded payload */
    private static function parse(string $envelope): array
    {
        $parts = explode('.', $envelope);
        if (count($parts) !== 3 || $parts[0] !== self::VERSION || preg_match('/^[a-z0-9][a-z0-9-]{0,31}$/', $parts[1]) !== 1) {
            throw new DecryptionFailed('Malformed secret envelope.');
        }
        try {
            $payload = sodium_base642bin($parts[2], SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        } catch (\SodiumException) {
            throw new DecryptionFailed('Malformed secret envelope payload.');
        }

        return [$parts[1], $payload];
    }
}
