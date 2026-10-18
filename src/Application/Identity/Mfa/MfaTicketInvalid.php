<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Identity\Mfa;

/** No live MFA ticket: missing, expired, used or locked out. */
final class MfaTicketInvalid extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('The sign-in step has expired. Please sign in again.');
    }
}
