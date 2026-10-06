<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Provider;

use RuntimeException;

/**
 * A classified failure from a provider call. The message is built from the
 * provider's error code and our own text only; it never contains request
 * headers, credentials or raw response bodies (threat model V-02, V-16).
 */
final class ProviderError extends RuntimeException
{
    public function __construct(
        public readonly string $provider,
        public readonly ProviderErrorClass $class,
        public readonly string $providerCode,
        public readonly ?int $httpStatus = null,
        public readonly ?int $retryAfterSeconds = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf('%s error %s (%s)%s', $provider, $providerCode, $class->value, $httpStatus === null ? '' : ' HTTP ' . $httpStatus),
            0,
            $previous,
        );
    }
}
