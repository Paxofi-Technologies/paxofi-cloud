<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Staff\Passkeys;

/**
 * A passkey ceremony failed. The reason is a fixed code for the audit log;
 * callers show every reason as the same failure (§6.17).
 */
final class PasskeyRejected extends \RuntimeException
{
    public const string MALFORMED = 'malformed';
    public const string INVALID = 'invalid';
    public const string COUNTER = 'counter';
    public const string UNKNOWN_CREDENTIAL = 'unknown_credential';
    public const string CEREMONY = 'ceremony';
    public const string INVITATION = 'invitation';
    public const string LIMIT = 'limit';
    public const string THROTTLED = 'throttled';

    public function __construct(public readonly string $reason, public readonly int $retryAfter = 0)
    {
        parent::__construct('Passkey rejected: ' . $reason);
    }
}
