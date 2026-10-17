<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Email;

use PaxofiCloud\Application\Email\DeliveryFailureKind;

/** Maps provider HTTP statuses to retry/failover decisions (threat model E-1). */
final class HttpStatusClassifier
{
    public static function kind(int $status): DeliveryFailureKind
    {
        return match (true) {
            $status === 429, $status >= 500 => DeliveryFailureKind::Transient,
            $status === 401, $status === 403 => DeliveryFailureKind::Configuration,
            default => DeliveryFailureKind::Rejected,
        };
    }
}
