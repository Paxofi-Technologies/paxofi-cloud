<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Identity\Mfa;

/** The action needs TOTP to be on (or pending, for confirm). */
final class MfaNotEnabled extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Two-step verification is not on.');
    }
}
