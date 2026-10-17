<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Identity\Registration;

/** Input rejected before anything is stored. Field errors are stable codes, never echoed input. */
final class InvalidRegistration extends \RuntimeException
{
    /** @param array<string, string> $errors field => error code */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Registration input is invalid.');
    }
}
