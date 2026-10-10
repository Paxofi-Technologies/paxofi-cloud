<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Provider;

/** Where adapters obtain decrypted credentials, by ID, at the moment of use (threat model D-5). */
interface ProviderCredentialSource
{
    public function load(string $credentialId): ProviderCredential;
}
