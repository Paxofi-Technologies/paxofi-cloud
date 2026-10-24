<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Registrar;

/** One stored cost snapshot without its items (DR-13). */
final readonly class CostSnapshotSummary
{
    public function __construct(
        public string $id,
        public string $environment,
        public string $status,
        public ?string $quarantineReason,
        public int $itemCount,
        public int $refusedCount,
        public \DateTimeImmutable $fetchedAt,
    ) {
        if (!in_array($status, ['accepted', 'quarantined'], true) || ($status === 'quarantined') !== ($quarantineReason !== null)) {
            throw new \InvalidArgumentException('A snapshot is accepted, or quarantined with a reason.');
        }
    }
}
