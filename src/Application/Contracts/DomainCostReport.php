<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Contracts;

use PaxofiCloud\Application\Registrar\CostSnapshotSummary;
use PaxofiCloud\Application\Registrar\SyncRun;
use PaxofiCloud\Domain\Registrar\SkippedTld;
use PaxofiCloud\Domain\Registrar\TldCost;

/** Read side of the domain cost snapshots, for the admin console (DR-13). */
interface DomainCostReport
{
    /** The newest snapshot for this provider and environment; with $acceptedOnly, the newest accepted one. */
    public function latestSnapshot(string $provider, string $environment, bool $acceptedOnly): ?CostSnapshotSummary;

    /** @return array<string, TldCost> keyed and ordered by extension */
    public function items(string $snapshotId): array;

    /** @return list<SkippedTld> in the order the registrar sent them (DR-14) */
    public function skipped(string $snapshotId): array;

    /** @return list<SyncRun> newest first */
    public function recentRuns(string $provider, int $limit): array;
}
