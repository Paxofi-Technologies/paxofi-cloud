<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Provider;

/** Provider error taxonomy (SRS PRV-005). Drives the job retry decision (PRV-004). */
enum ProviderErrorClass: string
{
    case Retryable = 'RETRYABLE';
    case NonRetryableInput = 'NON_RETRYABLE_INPUT';
    case NonRetryableAccount = 'NON_RETRYABLE_ACCOUNT';
    case ProviderCapacity = 'PROVIDER_CAPACITY';
    case Unknown = 'UNKNOWN';

    public function isRetryable(): bool
    {
        return $this === self::Retryable || $this === self::ProviderCapacity;
    }
}
