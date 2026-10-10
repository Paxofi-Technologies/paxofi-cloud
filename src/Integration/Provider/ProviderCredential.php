<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Provider;

use LogicException;

/**
 * A decrypted provider API credential (threat model V-01, V-02).
 *
 * The secret can only be read through reveal(), which transports call when
 * building the Authorization header. It never appears in var_dump/print_r,
 * exception traces (SensitiveParameter) or string conversion, and the object
 * refuses serialisation so it can never end up in a queued job payload.
 */
final class ProviderCredential
{
    private readonly string $secret;

    public function __construct(
        public readonly string $credentialId,
        #[\SensitiveParameter] string $secret,
    ) {
        if ($credentialId === '' || $secret === '') {
            throw new \InvalidArgumentException('Provider credential ID and secret must not be empty.');
        }
        if (preg_match('/[\x00-\x20\x7f]/', $secret) === 1) {
            throw new \InvalidArgumentException('Provider credential contains whitespace or control characters.');
        }

        $this->secret = $secret;
    }

    public function reveal(): string
    {
        return $this->secret;
    }

    /** @return array{credentialId: string, secret: string} */
    public function __debugInfo(): array
    {
        return ['credentialId' => $this->credentialId, 'secret' => '[redacted]'];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Provider credentials must not be serialised; pass the credential ID instead.');
    }

    /** @param array<mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new LogicException('Provider credentials must not be unserialised.');
    }
}
