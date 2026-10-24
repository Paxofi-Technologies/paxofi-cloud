<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Support;

use PaxofiCloud\Application\Contracts\DomainCostReport;
use PaxofiCloud\Application\Contracts\DomainCostStore;
use PaxofiCloud\Application\Registrar\CostSnapshotSummary;
use PaxofiCloud\Application\Registrar\SyncRun;
use PaxofiCloud\Domain\Registrar\TldCostList;

class InMemoryDomainCostStore implements DomainCostStore, DomainCostReport
{
    /** @var list<array{id: string, provider: string, list: TldCostList, reason: ?string, at: \DateTimeImmutable}> */
    public array $snapshots = [];

    /** @var list<SyncRun> oldest first */
    public array $runs = [];

    public function latestAccepted(string $provider, string $environment): ?array
    {
        foreach (array_reverse($this->snapshots) as $snapshot) {
            if ($snapshot['provider'] === $provider && $snapshot['list']->environment === $environment && $snapshot['reason'] === null) {
                return $snapshot['list']->items;
            }
        }

        return null;
    }

    public function saveSnapshot(string $provider, TldCostList $list, ?string $quarantineReason, \DateTimeImmutable $fetchedAt): string
    {
        $id = sprintf('snap%028d', count($this->snapshots) + 1);
        $this->snapshots[] = ['id' => $id, 'provider' => $provider, 'list' => $list, 'reason' => $quarantineReason, 'at' => $fetchedAt];

        return $id;
    }

    public function recordRun(SyncRun $run): void
    {
        $this->runs[] = $run;
    }

    public function recentRuns(string $provider, int $limit): array
    {
        $runs = array_values(array_filter(array_reverse($this->runs), static fn (SyncRun $r): bool => $r->provider === $provider));

        return array_slice($runs, 0, $limit);
    }

    public function lastStartedAt(string $provider, string $trigger): ?\DateTimeImmutable
    {
        $last = null;
        foreach ($this->runs as $run) {
            if ($run->provider === $provider && $run->trigger === $trigger && ($last === null || $run->startedAt > $last)) {
                $last = $run->startedAt;
            }
        }

        return $last;
    }

    public function latestSnapshot(string $provider, string $environment, bool $acceptedOnly): ?CostSnapshotSummary
    {
        foreach (array_reverse($this->snapshots) as $snapshot) {
            if ($snapshot['provider'] === $provider && $snapshot['list']->environment === $environment && (!$acceptedOnly || $snapshot['reason'] === null)) {
                return new CostSnapshotSummary(
                    $snapshot['id'],
                    $environment,
                    $snapshot['reason'] === null ? 'accepted' : 'quarantined',
                    $snapshot['reason'],
                    count($snapshot['list']->items),
                    $snapshot['list']->refusedTotal(),
                    $snapshot['at'],
                );
            }
        }

        return null;
    }

    public function items(string $snapshotId): array
    {
        foreach ($this->snapshots as $snapshot) {
            if ($snapshot['id'] === $snapshotId) {
                $items = $snapshot['list']->items;
                ksort($items, SORT_STRING);

                return $items;
            }
        }

        return [];
    }

    public function skipped(string $snapshotId): array
    {
        foreach ($this->snapshots as $snapshot) {
            if ($snapshot['id'] === $snapshotId) {
                return $snapshot['list']->skipped;
            }
        }

        return [];
    }
}
