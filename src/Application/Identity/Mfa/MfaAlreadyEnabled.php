<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Identity\Mfa;

/** TOTP is already confirmed; it must be turned off before a new setup. */
final class MfaAlreadyEnabled extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Two-step verification is already on.');
    }
}
