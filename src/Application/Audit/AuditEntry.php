<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Audit;

/** One recorded event as shown to its owner; context fields are null once purged (T-51). */
final readonly class AuditEntry
{
    /** @param array<string, string|int|bool> $details */
    public function __construct(
        public string $id,
        public \DateTimeImmutable $occurredAt,
        public string $type,
        public string $outcome,
        public array $details,
        public ?string $ip,
        public ?string $country,
        public ?string $region,
        public ?string $city,
        public ?DeviceSummary $device,
    ) {
    }
}
