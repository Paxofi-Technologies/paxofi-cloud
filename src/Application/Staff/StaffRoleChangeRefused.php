<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Staff;

/** A role change that the rules refuse (threat model §6.16); the reason is a fixed code. */
final class StaffRoleChangeRefused extends \RuntimeException
{
    public const string SELF_GRANT = 'self_grant';
    public const string UNKNOWN_STAFF = 'unknown_staff';
    public const string ALREADY_GRANTED = 'already_granted';
    public const string NOT_PENDING = 'not_pending';
    public const string SAME_PERSON = 'same_person';
    public const string NOT_GRANTED = 'not_granted';
    public const string LAST_ADMIN = 'last_admin';
    public const string NOT_SOLE_FOUNDER = 'not_sole_founder';
    public const string FOUNDER_MISMATCH = 'founder_mismatch';

    public function __construct(public readonly string $reason)
    {
        parent::__construct('Staff role change refused: ' . $reason);
    }
}
