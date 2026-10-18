<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Identity\Mfa;

/** What the customer copies into the authenticator app; shown once. */
final readonly class TotpSetup
{
    public function __construct(#[\SensitiveParameter] public string $secretBase32, #[\SensitiveParameter] public string $uri)
    {
    }
}
