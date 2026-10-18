<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Staff\Passkeys;

/** The state a verified sign-in leaves the passkey in. */
final readonly class VerifiedAssertion
{
    public function __construct(public int $signCount, public bool $backupState)
    {
    }
}
