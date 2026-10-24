<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Support;

use PaxofiCloud\Integration\Provider\ProviderCredential;
use PaxofiCloud\Integration\Provider\ProviderCredentialSource;

/** Credentials held in memory for adapter tests; counts loads so tests can prove none happened. */
final class StaticCredentialSource implements ProviderCredentialSource
{
    public int $loads = 0;

    /** @param array<string, string> $secrets credential ID => secret */
    public function __construct(private array $secrets)
    {
    }

    public function load(string $credentialId): ProviderCredential
    {
        $this->loads++;
        $secret = $this->secrets[$credentialId] ?? throw new \OutOfBoundsException('No provider credential.');

        return new ProviderCredential($credentialId, $secret);
    }
}
