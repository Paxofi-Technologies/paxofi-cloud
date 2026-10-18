<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Identity\Mfa;

/** Rejected MFA input. Field errors are stable codes (`incorrect`, `required`, …), never echoed input. */
final class InvalidMfaInput extends \RuntimeException
{
    /** @param array<string, string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Two-step verification input is invalid.');
    }
}
