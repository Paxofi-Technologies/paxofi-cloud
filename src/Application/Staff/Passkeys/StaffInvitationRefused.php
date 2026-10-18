<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Staff\Passkeys;

/** An invitation the rules refuse (§6.17); the reason is a fixed code. */
final class StaffInvitationRefused extends \RuntimeException
{
    public const string INVALID_INPUT = 'invalid_input';
    public const string ALREADY_STAFF = 'already_staff';
    public const string MANAGERS_EXIST = 'managers_exist';
    public const string ROLE_NEEDS_APPROVAL = 'role_needs_approval';

    public function __construct(public readonly string $reason)
    {
        parent::__construct('Staff invitation refused: ' . $reason);
    }
}
