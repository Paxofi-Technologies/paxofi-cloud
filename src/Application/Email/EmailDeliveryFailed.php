<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Email;

/** Messages are fixed descriptions plus provider status/codes; never addresses or bodies. */
final class EmailDeliveryFailed extends \RuntimeException
{
    public function __construct(
        public readonly DeliveryFailureKind $kind,
        public readonly string $provider,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
