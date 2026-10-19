<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Staff\Setup;

/** Why sign-in setup cannot start or finish (§6.20); fixed reason codes. */
final class StaffSetupRefused extends \RuntimeException
{
    /** Unknown, used or expired invitation. */
    public const string INVITATION = 'invitation';
    /** The member already has a password, or is not active. */
    public const string NOT_ALLOWED = 'not_allowed';
    public const string THROTTLED = 'throttled';
    /** The server has no MFA keys. */
    public const string UNAVAILABLE = 'unavailable';

    public function __construct(public readonly string $reason, public readonly int $retryAfter = 0)
    {
        parent::__construct('Staff sign-in setup refused: ' . $reason);
    }
}
