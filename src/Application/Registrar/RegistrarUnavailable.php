<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Registrar;

/**
 * A registrar call failed as a whole. The reason is a fixed code (DR-9);
 * provider bodies and headers are never carried.
 */
final class RegistrarUnavailable extends \RuntimeException
{
    public const array REASONS = [
        'unauthorised', 'rate_limited', 'provider_error', 'malformed', 'transport',
        'too_many_pages', 'credential_missing', 'credential_invalid',
    ];

    public function __construct(public readonly string $reason)
    {
        if (!in_array($reason, self::REASONS, true)) {
            throw new \InvalidArgumentException('Unknown registrar failure reason.');
        }
        parent::__construct('Registrar unavailable: ' . $reason);
    }

    /** Retrying cannot help until staff replace the credential (DR-9). */
    public function needsNewCredential(): bool
    {
        return in_array($this->reason, ['unauthorised', 'credential_missing', 'credential_invalid'], true);
    }

    /** Worth a bounded retry with backoff (DR-9). */
    public function isRetryable(): bool
    {
        return in_array($this->reason, ['rate_limited', 'provider_error', 'transport'], true);
    }
}
