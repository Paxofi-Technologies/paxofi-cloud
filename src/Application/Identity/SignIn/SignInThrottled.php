<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Identity\SignIn;

final class SignInThrottled extends \RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct('Too many sign-in attempts.');
    }
}
