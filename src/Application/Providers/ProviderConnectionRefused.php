<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Providers;

/** Why a provider credential was not accepted. Reasons are fixed codes; never a submitted value. */
final class ProviderConnectionRefused extends \RuntimeException
{
    public const string UNKNOWN_PROVIDER = 'unknown_provider';
    public const string INVALID_FIELDS = 'invalid_fields';
    public const string STEP_UP = 'code_invalid';
    public const string THROTTLED = 'too_many_attempts';
    public const string UNAVAILABLE = 'intake_unavailable';

    public function __construct(public readonly string $reason, public readonly ?string $field = null, public readonly int $retryAfter = 0)
    {
        parent::__construct($reason);
    }
}
