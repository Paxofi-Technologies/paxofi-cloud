<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Staff\SignIn;

/**
 * Why a staff code sign-in step stopped (§6.20). The caller only learns the
 * category; the audit log keeps the fixed reason.
 */
final class StaffSignInRefused extends \RuntimeException
{
    /** Wrong address or password, or a member who cannot sign in this way. */
    public const string FAILED = 'failed';
    /** Wrong code; the sign-in continues with a new ticket. */
    public const string CODE_INVALID = 'code_invalid';
    /** No live ticket (expired, used, or too many wrong codes): start again. */
    public const string EXPIRED = 'expired';
    public const string THROTTLED = 'throttled';
    /** The server has no MFA keys or the email could not be queued. */
    public const string UNAVAILABLE = 'unavailable';

    public function __construct(public readonly string $reason, public readonly int $retryAfter = 0, public readonly ?string $ticket = null)
    {
        parent::__construct('Staff sign-in refused: ' . $reason);
    }
}
