<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Payments;

/**
 * A gateway call failed as a whole. The reason is a fixed code; gateway
 * bodies, messages and headers are never carried (PY-19).
 */
final class GatewayUnavailable extends \RuntimeException
{
    public const array REASONS = [
        'unauthorised', 'rate_limited', 'provider_error', 'malformed', 'transport', 'rejected',
        'credential_missing', 'credential_invalid', 'key_mode_mismatch', 'below_minimum', 'unexpected_checkout_url',
    ];

    public function __construct(public readonly string $reason)
    {
        if (!in_array($reason, self::REASONS, true)) {
            throw new \InvalidArgumentException('Unknown gateway failure reason.');
        }
        parent::__construct('Payment gateway unavailable: ' . $reason);
    }

    /** Retrying cannot help until staff replace the credential (PY-4: a wrong-mode key also needs a new one). */
    public function needsNewCredential(): bool
    {
        return in_array($this->reason, ['unauthorised', 'credential_missing', 'credential_invalid', 'key_mode_mismatch'], true);
    }

    /** Worth a bounded retry with backoff. */
    public function isRetryable(): bool
    {
        return in_array($this->reason, ['rate_limited', 'provider_error', 'transport'], true);
    }
}
