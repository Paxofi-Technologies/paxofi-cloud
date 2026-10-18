<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Identity\SignIn;

/** Correct password, but the address was never confirmed. */
final class EmailNotVerified extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Email address not verified.');
    }
}
