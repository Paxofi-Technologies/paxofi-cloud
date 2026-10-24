<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Contracts;

use PaxofiCloud\Application\Registrar\SyncRun;
use PaxofiCloud\Domain\Registrar\TldCost;
use PaxofiCloud\Domain\Registrar\TldCostList;

/**
 * Append-only storage of domain cost snapshots and sync runs (CD-13, DR-8).
 * Nothing here is ever updated or deleted by the application.
 */
interface DomainCostStore
{
    /** @return array<string, TldCost>|null the newest accepted snapshot's items for this provider and environment */
    public function latestAccepted(string $provider, string $environment): ?array;

    /** @param 'empty'|'items_disappeared'|'costs_jumped'|null $quarantineReason null = accepted */
    public function saveSnapshot(string $provider, TldCostList $list, ?string $quarantineReason, \DateTimeImmutable $fetchedAt): string;

    public function recordRun(SyncRun $run): void;

    /** @return list<SyncRun> newest first */
    public function recentRuns(string $provider, int $limit): array;

    /** When the newest run with this trigger started, or null if there has been none. */
    public function lastStartedAt(string $provider, string $trigger): ?\DateTimeImmutable;
}
