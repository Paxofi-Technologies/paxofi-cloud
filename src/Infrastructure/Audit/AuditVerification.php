<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Audit;

/** Result of walking the audit chain; `brokenAt` is the first bad sequence number. */
final readonly class AuditVerification
{
    public function __construct(
        public int $events,
        public string $headHash,
        public ?int $brokenAt = null,
        public string $reason = '',
        public int $contextsPurged = 0,
    ) {
    }

    public function ok(): bool
    {
        return $this->brokenAt === null;
    }
}
