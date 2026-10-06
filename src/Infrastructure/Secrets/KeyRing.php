<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Secrets;

/**
 * Key-encryption keys (KEKs) for secrets stored in the database (SRS SEC-004).
 *
 * Keys come only from the environment, never from the database they protect:
 *   PROVIDER_CREDENTIAL_KEYS="k2026a:<base64 32 bytes>,k2025b:<base64 32 bytes>"
 *   PROVIDER_CREDENTIAL_ACTIVE_KEY="k2026a"
 * New ciphertexts use the active key; older keys stay listed until every row
 * has been re-encrypted, which is what makes rotation possible without downtime.
 */
final class KeyRing
{
    private const string KEY_ID = '/^[a-z0-9][a-z0-9-]{0,31}$/';

    /** @var array<string, string> */
    private array $keys;

    /** @param array<string, string> $keys key ID => raw 32-byte key */
    public function __construct(array $keys, public readonly string $activeKeyId)
    {
        if ($keys === []) {
            throw new InvalidKeyRing('At least one key is required.');
        }
        foreach ($keys as $id => $key) {
            if (preg_match(self::KEY_ID, $id) !== 1) {
                throw new InvalidKeyRing('Key IDs must be 1-32 characters of a-z, 0-9 and "-".');
            }
            if (strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
                throw new InvalidKeyRing(sprintf('Key "%s" must be exactly %d bytes.', $id, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES));
            }
        }
        if (!isset($keys[$activeKeyId])) {
            throw new InvalidKeyRing('The active key ID is not in the key list.');
        }
        if (count(array_unique($keys)) !== count($keys)) {
            throw new InvalidKeyRing('Two key IDs share the same key material.');
        }

        $this->keys = $keys;
    }

    public static function fromEnvironment(#[\SensitiveParameter] string $keyList, string $activeKeyId): self
    {
        $keys = [];
        foreach (explode(',', $keyList) as $entry) {
            $parts = explode(':', trim($entry), 2);
            if (count($parts) !== 2) {
                throw new InvalidKeyRing('Each key entry must be "<id>:<base64 key>".');
            }
            [$id, $encoded] = $parts;
            if (isset($keys[$id])) {
                throw new InvalidKeyRing(sprintf('Key ID "%s" is listed twice.', $id));
            }
            $raw = base64_decode($encoded, true);
            if ($raw === false) {
                throw new InvalidKeyRing(sprintf('Key "%s" is not valid base64.', $id));
            }
            $keys[$id] = $raw;
        }

        return new self($keys, trim($activeKeyId));
    }

    /**
     * Reads PROVIDER_CREDENTIAL_KEYS and PROVIDER_CREDENTIAL_ACTIVE_KEY from the
     * process environment at the moment of use. Deliberately not part of the
     * configuration files, which may be cached to disk in production.
     */
    public static function fromProcessEnvironment(): self
    {
        $keys = getenv('PROVIDER_CREDENTIAL_KEYS');
        $active = getenv('PROVIDER_CREDENTIAL_ACTIVE_KEY');
        if (!is_string($keys) || $keys === '' || !is_string($active) || $active === '') {
            throw new InvalidKeyRing('PROVIDER_CREDENTIAL_KEYS and PROVIDER_CREDENTIAL_ACTIVE_KEY must be set (workers and operator CLI only).');
        }

        return self::fromEnvironment($keys, $active);
    }

    /** Generates a new random key in the PROVIDER_CREDENTIAL_KEYS entry format. */
    public static function generateEntry(string $keyId): string
    {
        if (preg_match(self::KEY_ID, $keyId) !== 1) {
            throw new InvalidKeyRing('Key IDs must be 1-32 characters of a-z, 0-9 and "-".');
        }

        return $keyId . ':' . base64_encode(sodium_crypto_aead_xchacha20poly1305_ietf_keygen());
    }

    public function activeKey(): string
    {
        return $this->keys[$this->activeKeyId];
    }

    public function key(string $keyId): ?string
    {
        return $this->keys[$keyId] ?? null;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['activeKeyId' => $this->activeKeyId, 'keyIds' => array_keys($this->keys), 'keys' => '[redacted]'];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new \LogicException('Key rings must not be serialised.');
    }

    /** @param array<mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('Key rings must not be unserialised.');
    }
}
