<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Identity\Registration;

/** Unknown, malformed, used and expired tokens are deliberately indistinguishable (IAM-002). */
final class VerificationTokenInvalid extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('This verification link has already been used or has expired.');
    }
}
