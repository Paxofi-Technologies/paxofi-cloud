<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Identity\Mfa;

/** Password accepted; the raw ticket goes into the `__Host-pc_mfa` cookie, once. */
final readonly class MfaPending
{
    public function __construct(#[\SensitiveParameter] public string $ticket)
    {
    }
}
