<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Identity\SignIn;

/** Unknown email or wrong password: one outcome for both (T-09). */
final class InvalidCredentials extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Email or password is incorrect.');
    }
}
