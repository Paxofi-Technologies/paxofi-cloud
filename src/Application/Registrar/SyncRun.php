<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Registrar;

/** One finished provider sync, recorded once (DR-8). */
final readonly class SyncRun
{
    public const array TRIGGERS = ['schedule', 'manual', 'cli'];
    public const array OUTCOMES = ['accepted', 'quarantined', 'failed'];

    public function __construct(
        public string $provider,
        public string $trigger,
        public string $outcome,
        public ?string $reason,
        public ?string $snapshotId,
        public int $attempts,
        public \DateTimeImmutable $startedAt,
        public \DateTimeImmutable $finishedAt,
    ) {
        if (!in_array($trigger, self::TRIGGERS, true) || !in_array($outcome, self::OUTCOMES, true)) {
            throw new \InvalidArgumentException('Unknown sync trigger or outcome.');
        }
        if (($outcome === 'failed') !== ($snapshotId === null) || ($outcome === 'failed') !== ($reason !== null)) {
            throw new \InvalidArgumentException('A failed run has a reason and no snapshot; any other run has a snapshot and no reason.');
        }
        if ($attempts < 1 || $attempts > DomainCostSync::MAX_ATTEMPTS) {
            throw new \InvalidArgumentException('Attempts out of range.');
        }
    }
}
