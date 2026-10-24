<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Registrar;

use Paxofi\Core\Contracts\Connection;
use Paxofi\Core\Contracts\Repository;
use Paxofi\Core\Contracts\TransactionManager;
use PaxofiCloud\Application\Contracts\DomainCostReport;
use PaxofiCloud\Application\Contracts\DomainCostStore;
use PaxofiCloud\Application\Registrar\CostSnapshotSummary;
use PaxofiCloud\Application\Registrar\SyncRun;
use PaxofiCloud\Domain\Pricing\CostCurrency;
use PaxofiCloud\Domain\Registrar\CostAmount;
use PaxofiCloud\Domain\Registrar\FeedField;
use PaxofiCloud\Domain\Registrar\SkippedTld;
use PaxofiCloud\Domain\Registrar\TldCost;
use PaxofiCloud\Domain\Registrar\TldCostList;
use PaxofiCloud\Domain\Registrar\TldName;
use PaxofiCloud\Infrastructure\Persistence\Row;

/** MySQL domain cost snapshots and sync runs (migration 0013; append-only for the runtime user), and their read side (DR-13). */
final readonly class MysqlDomainCostStore implements DomainCostStore, DomainCostReport
{
    private const string TIME_FORMAT = 'Y-m-d H:i:s.u';
    private const int BATCH = 100;

    public function __construct(
        private Connection $connection,
        private Repository $repository,
        private TransactionManager $transactions,
    ) {
    }

    public function latestAccepted(string $provider, string $environment): ?array
    {
        $snapshot = $this->repository->fetchAll(
            "SELECT id FROM domain_cost_snapshots WHERE provider = :provider AND environment = :environment AND status = 'accepted'
             ORDER BY fetched_at DESC, id DESC LIMIT 1",
            ['provider' => $provider, 'environment' => $environment],
        )[0] ?? null;
        if ($snapshot === null) {
            return null;
        }

        return $this->items(Row::string($snapshot, 'id'));
    }

    public function latestSnapshot(string $provider, string $environment, bool $acceptedOnly): ?CostSnapshotSummary
    {
        $row = $this->repository->fetchAll(
            "SELECT id, environment, status, quarantine_reason, item_count, refused_count, fetched_at FROM domain_cost_snapshots
             WHERE provider = :provider AND environment = :environment AND (:accepted_only = 0 OR status = 'accepted')
             ORDER BY fetched_at DESC, id DESC LIMIT 1",
            ['provider' => $provider, 'environment' => $environment, 'accepted_only' => $acceptedOnly ? 1 : 0],
        )[0] ?? null;
        if ($row === null) {
            return null;
        }

        return new CostSnapshotSummary(
            Row::string($row, 'id'),
            Row::string($row, 'environment'),
            Row::string($row, 'status'),
            ($row['quarantine_reason'] ?? null) === null ? null : Row::string($row, 'quarantine_reason'),
            Row::int($row, 'item_count'),
            Row::int($row, 'refused_count'),
            self::parse(Row::string($row, 'fetched_at')),
        );
    }

    /** @return array<string, TldCost> */
    public function items(string $snapshotId): array
    {
        $items = [];
        foreach ($this->repository->fetchAll(
            'SELECT tld, currency, register_minor, renew_minor, transfer_minor, restore_minor, min_years, max_years, provider_status
             FROM domain_cost_items WHERE snapshot_id = :id ORDER BY tld',
            ['id' => $snapshotId],
        ) as $row) {
            $currency = CostCurrency::from(Row::string($row, 'currency'));
            $optional = static fn (string $column): ?CostAmount => ($row[$column] ?? null) === null ? null : CostAmount::ofMinor($currency, Row::int($row, $column));
            $tld = Row::string($row, 'tld');
            $items[$tld] = new TldCost(
                new TldName($tld),
                CostAmount::ofMinor($currency, Row::int($row, 'register_minor')),
                CostAmount::ofMinor($currency, Row::int($row, 'renew_minor')),
                $optional('transfer_minor'),
                $optional('restore_minor'),
                Row::int($row, 'min_years'),
                Row::int($row, 'max_years'),
                Row::string($row, 'provider_status'),
            );
        }

        return $items;
    }

    public function saveSnapshot(string $provider, TldCostList $list, ?string $quarantineReason, \DateTimeImmutable $fetchedAt): string
    {
        $id = bin2hex(random_bytes(16));
        $this->transactions->transaction(function () use ($id, $provider, $list, $quarantineReason, $fetchedAt): void {
            $this->connection->execute(
                'INSERT INTO domain_cost_snapshots (id, provider, environment, status, quarantine_reason, item_count, refused_count, refused_summary, fetched_at)
                 VALUES (:id, :provider, :environment, :status, :reason, :items, :refused, :summary, :fetched_at)',
                [
                    'id' => $id,
                    'provider' => $provider,
                    'environment' => $list->environment,
                    'status' => $quarantineReason === null ? 'accepted' : 'quarantined',
                    'reason' => $quarantineReason,
                    'items' => count($list->items),
                    'refused' => $list->refusedTotal(),
                    'summary' => json_encode((object) $list->refused, JSON_THROW_ON_ERROR),
                    'fetched_at' => self::utc($fetchedAt),
                ],
            );
            foreach (array_chunk(array_values($list->items), self::BATCH) as $batch) {
                $rows = [];
                $parameters = [];
                foreach ($batch as $i => $item) {
                    $rows[] = sprintf('(:s%1$d, :t%1$d, :c%1$d, :g%1$d, :n%1$d, :x%1$d, :r%1$d, :a%1$d, :b%1$d, :p%1$d)', $i);
                    $parameters += [
                        's' . $i => $id,
                        't' . $i => $item->tld->value,
                        'c' . $i => $item->register->currency->value,
                        'g' . $i => $item->register->minor,
                        'n' . $i => $item->renew->minor,
                        'x' . $i => $item->transfer?->minor,
                        'r' . $i => $item->restore?->minor,
                        'a' . $i => $item->minYears,
                        'b' . $i => $item->maxYears,
                        'p' . $i => $item->providerStatus,
                    ];
                }
                $this->connection->execute(
                    'INSERT INTO domain_cost_items (snapshot_id, tld, currency, register_minor, renew_minor, transfer_minor, restore_minor, min_years, max_years, provider_status) VALUES '
                    . implode(', ', $rows),
                    $parameters,
                );
            }
            foreach (array_chunk($list->skipped, self::BATCH, true) as $batch) {
                $rows = [];
                $parameters = [];
                foreach ($batch as $seq => $skip) {
                    $rows[] = sprintf('(:s%1$d, :q%1$d, :n%1$d, :r%1$d, :d%1$d)', $seq);
                    $parameters += ['s' . $seq => $id, 'q' . $seq => $seq, 'n' . $seq => $skip->name, 'r' . $seq => $skip->reason, 'd' . $seq => $skip->detail];
                }
                $this->connection->execute(
                    'INSERT INTO domain_cost_skipped (snapshot_id, seq, name, reason, detail) VALUES ' . implode(', ', $rows),
                    $parameters,
                );
            }
            foreach (array_chunk($list->fields, self::BATCH) as $batch) {
                $rows = [];
                $parameters = [];
                foreach ($batch as $i => $field) {
                    $rows[] = sprintf('(:s%1$d, :p%1$d, :i%1$d, :k%1$d, :a%1$d, :b%1$d)', $i);
                    $parameters += ['s' . $i => $id, 'p' . $i => $field->path, 'i' . $i => $field->items, 'k' . $i => implode(',', $field->kinds), 'a' . $i => $field->min, 'b' . $i => $field->max];
                }
                $this->connection->execute(
                    'INSERT INTO domain_cost_feed_fields (snapshot_id, path, items, kinds, min_value, max_value) VALUES ' . implode(', ', $rows),
                    $parameters,
                );
            }
        });

        return $id;
    }

    public function skipped(string $snapshotId): array
    {
        $skipped = [];
        foreach ($this->repository->fetchAll(
            'SELECT name, reason, detail FROM domain_cost_skipped WHERE snapshot_id = :id ORDER BY seq',
            ['id' => $snapshotId],
        ) as $row) {
            $skipped[] = new SkippedTld(
                ($row['name'] ?? null) === null ? null : Row::string($row, 'name'),
                Row::string($row, 'reason'),
                ($row['detail'] ?? null) === null ? null : Row::string($row, 'detail'),
            );
        }

        return $skipped;
    }

    public function feedFields(string $snapshotId): array
    {
        $fields = [];
        foreach ($this->repository->fetchAll(
            'SELECT path, items, kinds, min_value, max_value FROM domain_cost_feed_fields WHERE snapshot_id = :id ORDER BY path',
            ['id' => $snapshotId],
        ) as $row) {
            $fields[] = new FeedField(
                Row::string($row, 'path'),
                Row::int($row, 'items'),
                explode(',', Row::string($row, 'kinds')),
                ($row['min_value'] ?? null) === null ? null : Row::int($row, 'min_value'),
                ($row['max_value'] ?? null) === null ? null : Row::int($row, 'max_value'),
            );
        }

        return $fields;
    }

    public function recordRun(SyncRun $run): void
    {
        $this->connection->execute(
            "INSERT INTO provider_sync_runs (id, provider, kind, trigger_source, outcome, reason, snapshot_id, attempts, started_at, finished_at)
             VALUES (:id, :provider, 'domain_costs', :trigger, :outcome, :reason, :snapshot, :attempts, :started_at, :finished_at)",
            [
                'id' => bin2hex(random_bytes(16)),
                'provider' => $run->provider,
                'trigger' => $run->trigger,
                'outcome' => $run->outcome,
                'reason' => $run->reason,
                'snapshot' => $run->snapshotId,
                'attempts' => $run->attempts,
                'started_at' => self::utc($run->startedAt),
                'finished_at' => self::utc($run->finishedAt),
            ],
        );
    }

    public function recentRuns(string $provider, int $limit): array
    {
        $rows = $this->repository->fetchAll(
            "SELECT trigger_source, outcome, reason, snapshot_id, attempts, started_at, finished_at FROM provider_sync_runs
             WHERE provider = :provider AND kind = 'domain_costs' ORDER BY started_at DESC, id DESC LIMIT 50",
            ['provider' => $provider],
        );
        $runs = [];
        foreach (array_slice($rows, 0, max(1, min(50, $limit))) as $row) {
            $runs[] = new SyncRun(
                $provider,
                Row::string($row, 'trigger_source'),
                Row::string($row, 'outcome'),
                ($row['reason'] ?? null) === null ? null : Row::string($row, 'reason'),
                ($row['snapshot_id'] ?? null) === null ? null : Row::string($row, 'snapshot_id'),
                Row::int($row, 'attempts'),
                self::parse(Row::string($row, 'started_at')),
                self::parse(Row::string($row, 'finished_at')),
            );
        }

        return $runs;
    }

    public function lastStartedAt(string $provider, string $trigger): ?\DateTimeImmutable
    {
        $row = $this->repository->fetchAll(
            "SELECT MAX(started_at) AS started_at FROM provider_sync_runs WHERE provider = :provider AND kind = 'domain_costs' AND trigger_source = :trigger",
            ['provider' => $provider, 'trigger' => $trigger],
        )[0] ?? null;

        return $row === null || ($row['started_at'] ?? null) === null ? null : self::parse(Row::string($row, 'started_at'));
    }

    private static function utc(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format(self::TIME_FORMAT);
    }

    private static function parse(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }
}
