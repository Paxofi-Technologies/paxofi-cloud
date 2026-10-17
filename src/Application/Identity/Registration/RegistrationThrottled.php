<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Identity\Registration;

final class RegistrationThrottled extends \RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct('Too many registration attempts.');
    }
}
