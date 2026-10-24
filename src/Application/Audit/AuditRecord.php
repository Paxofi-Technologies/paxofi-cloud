<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Audit;

/**
 * One audit event as staff see it (§6.24). Context fields are null when the
 * event had no request context or it was purged after 180 days (T-51).
 */
final readonly class AuditRecord
{
    /** @param array<string, string|int|bool> $details */
    public function __construct(
        public int $seq,
        public \DateTimeImmutable $occurredAt,
        public string $type,
        public string $outcome,
        public ?string $identityId,
        public ?string $accountId,
        public array $details,
        public ?string $ip,
        public ?string $ipNetwork,
        public ?string $country,
        public ?string $region,
        public ?string $city,
        public ?string $userAgent,
        public ?DeviceSummary $device,
    ) {
    }
}
