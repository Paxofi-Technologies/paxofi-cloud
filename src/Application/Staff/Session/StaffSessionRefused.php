<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Staff\Session;

/** A staff session change that was refused, with a fixed reason (§6.25). */
final class StaffSessionRefused extends \RuntimeException
{
    public const string STEP_UP = 'step_up_required';
    public const string THROTTLED = 'too_many_attempts';
    public const string NOT_FOUND = 'not_found';
    public const string OWN_ACCOUNT = 'own_account';

    public function __construct(public readonly string $reason, public readonly int $retryAfter = 0)
    {
        parent::__construct($reason);
    }
}
