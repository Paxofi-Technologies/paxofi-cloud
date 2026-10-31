<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Contracts;

use PaxofiCloud\Application\Payments\WebhookHint;

/**
 * Genuine gateway webhooks (THREAT-MODEL-PAYMENTS.md PY-7). Recording an
 * event and bringing its attempt's next check forward happen together, and
 * the second delivery of the same event changes nothing.
 */
interface GatewayEventStore
{
    /**
     * Records the event once and, the first time only, makes the matching
     * open attempt due for its next gateway check now.
     *
     * @return bool false when the same event was already recorded (a retry or replay)
     */
    public function record(WebhookHint $hint, string $payloadSha256, \DateTimeImmutable $now): bool;
}
