<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Secrets;

/** Stores a provider secret under the KEK (only the provider worker and operator CLI hold one). */
interface ProviderCredentialWriter
{
    public function save(StoredCredential $credential, #[\SensitiveParameter] string $secret, \DateTimeImmutable $reviewDueOn): void;
}
