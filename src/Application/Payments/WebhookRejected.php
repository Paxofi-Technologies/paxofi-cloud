<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Payments;

/** A webhook failed its signature check or was not readable; the endpoint answers 401/400 and records a fixed reason (PT-03). */
final class WebhookRejected extends \RuntimeException
{
    public const array REASONS = ['signature_missing', 'signature_invalid', 'too_large', 'malformed'];

    public function __construct(public readonly string $reason)
    {
        if (!in_array($reason, self::REASONS, true)) {
            throw new \InvalidArgumentException('Unknown webhook rejection reason.');
        }
        parent::__construct('Webhook rejected: ' . $reason);
    }
}
