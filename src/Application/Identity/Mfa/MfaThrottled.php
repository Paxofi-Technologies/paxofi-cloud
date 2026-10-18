<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Identity\Mfa;

final class MfaThrottled extends \RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct('Too many attempts.');
    }
}
