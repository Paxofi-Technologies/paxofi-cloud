<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Identity\SignIn;

/** Correct password, but the identity is suspended or otherwise not active. */
final class AccountUnavailable extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Account unavailable.');
    }
}
