<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Identity\SignIn;

/** Malformed sign-in input. Field errors are stable codes, never echoed input. */
final class InvalidSignIn extends \RuntimeException
{
    /** @param array<string, string> $errors field => error code */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Sign-in input is invalid.');
    }
}
