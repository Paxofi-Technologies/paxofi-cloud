<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Secrets;

/** Metadata of one stored provider credential. Everything here is bound into the ciphertext. */
final class StoredCredential
{
    public function __construct(
        public readonly string $id,
        public readonly string $provider,
        public readonly string $environment,
        public readonly string $accessLevel,
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $id) !== 1) {
            throw new \InvalidArgumentException('Credential IDs are 1-64 characters of a-z, 0-9 and "-".');
        }
        if (preg_match('/^[a-z][a-z0-9-]{0,31}$/', $provider) !== 1) {
            throw new \InvalidArgumentException('Provider must be a short lowercase name.');
        }
        if (!in_array($environment, ['dev', 'staging', 'prod'], true)) {
            throw new \InvalidArgumentException('Environment must be dev, staging or prod.');
        }
        if (!in_array($accessLevel, ['read-only', 'read-write'], true)) {
            throw new \InvalidArgumentException('Access level must be read-only or read-write.');
        }
    }

    /** Associated data for the AEAD: a ciphertext only decrypts for exactly this record. */
    public function associatedData(): string
    {
        return implode('|', ['provider_credentials', 'v1', $this->id, $this->provider, $this->environment, $this->accessLevel]);
    }
}
