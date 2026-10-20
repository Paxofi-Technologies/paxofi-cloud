<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Contracts;

/** Seals a credential for the provider worker; the web tier can never open what it sealed (PC-1). */
interface CredentialSealer
{
    public function seal(#[\SensitiveParameter] string $plaintext): string;
}
