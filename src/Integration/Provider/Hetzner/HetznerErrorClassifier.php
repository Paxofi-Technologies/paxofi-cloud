<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Provider\Hetzner;

use PaxofiCloud\Integration\Provider\ProviderErrorClass;

/**
 * Maps Hetzner Cloud API error codes to the PRV-005 taxonomy. Codes we do not
 * know fall back to the HTTP status, and anything still unclear is UNKNOWN,
 * which the job engine treats as non-retryable and routes to an operator.
 */
final class HetznerErrorClassifier
{
    private const array CODES = [
        // Retry with backoff.
        'rate_limit_exceeded' => ProviderErrorClass::Retryable,
        'locked' => ProviderErrorClass::Retryable,
        'conflict' => ProviderErrorClass::Retryable,
        'server_error' => ProviderErrorClass::Retryable,
        'service_error' => ProviderErrorClass::Retryable,
        'unavailable' => ProviderErrorClass::Retryable,
        'maintenance' => ProviderErrorClass::Retryable,
        'timeout' => ProviderErrorClass::Retryable,
        // The provider has no capacity for this request right now.
        'resource_unavailable' => ProviderErrorClass::ProviderCapacity,
        'placement_error' => ProviderErrorClass::ProviderCapacity,
        // Our request is wrong; retrying cannot help.
        'invalid_input' => ProviderErrorClass::NonRetryableInput,
        'json_error' => ProviderErrorClass::NonRetryableInput,
        'not_found' => ProviderErrorClass::NonRetryableInput,
        'uniqueness_error' => ProviderErrorClass::NonRetryableInput,
        'protected' => ProviderErrorClass::NonRetryableInput,
        'unsupported_error' => ProviderErrorClass::NonRetryableInput,
        'method_not_allowed' => ProviderErrorClass::NonRetryableInput,
        // Our account, token or limits need a human.
        'unauthorized' => ProviderErrorClass::NonRetryableAccount,
        'forbidden' => ProviderErrorClass::NonRetryableAccount,
        'token_readonly' => ProviderErrorClass::NonRetryableAccount,
        'resource_limit_exceeded' => ProviderErrorClass::NonRetryableAccount,
    ];

    public static function classify(string $code, int $httpStatus): ProviderErrorClass
    {
        return self::CODES[$code] ?? match (true) {
            $httpStatus === 429, $httpStatus >= 500 => ProviderErrorClass::Retryable,
            $httpStatus === 401, $httpStatus === 403 => ProviderErrorClass::NonRetryableAccount,
            $httpStatus >= 400 => ProviderErrorClass::NonRetryableInput,
            default => ProviderErrorClass::Unknown,
        };
    }
}
