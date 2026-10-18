<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Identity\Mfa;

/** Two-step verification is not configured on this server; refused rather than skipped. */
final class MfaUnavailable extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Two-step verification cannot be used right now (keys not configured).');
    }
}
