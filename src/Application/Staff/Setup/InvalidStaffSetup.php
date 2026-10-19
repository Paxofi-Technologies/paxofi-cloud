<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Staff\Setup;

/** Field errors the member can fix: `password` and `code` with fixed codes. */
final class InvalidStaffSetup extends \RuntimeException
{
    /** @param array<string, string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Staff sign-in setup has invalid fields.');
    }
}
