<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Registrar;

use Paxofi\Core\Contracts\Connection;
use Paxofi\Core\Contracts\Repository;
use PaxofiCloud\Application\Contracts\ProviderSyncRequests;
use PaxofiCloud\Infrastructure\Persistence\Row;

/** MySQL "Sync now" requests (migration 0014; DR-12). Rows are never deleted. */
final readonly class MysqlProviderSyncRequests implements ProviderSyncRequests
{
    private const string TIME_FORMAT = 'Y-m-d H:i:s.u';

    public function __construct(private Connection $connection, private Repository $repository)
    {
    }

    public function add(string $provider, string $staffId, \DateTimeImmutable $at): void
    {
        $this->connection->execute(
            "INSERT INTO provider_sync_requests (id, provider, kind, requested_by, requested_at) VALUES (:id, :provider, 'domain_costs', :staff, :at)",
            ['id' => bin2hex(random_bytes(16)), 'provider' => $provider, 'staff' => $staffId, 'at' => self::utc($at)],
        );
    }

    public function lastRequestedAt(string $provider): ?\DateTimeImmutable
    {
        return $this->time(
            "SELECT MAX(requested_at) AS at FROM provider_sync_requests WHERE provider = :provider AND kind = 'domain_costs'",
            $provider,
        );
    }

    public function pendingSince(string $provider): ?\DateTimeImmutable
    {
        return $this->time(
            "SELECT MIN(requested_at) AS at FROM provider_sync_requests WHERE provider = :provider AND kind = 'domain_costs' AND picked_up_at IS NULL",
            $provider,
        );
    }

    public function claimAll(string $provider, \DateTimeImmutable $at): int
    {
        // One statement: two workers cannot both claim the same row.
        return $this->connection->execute(
            "UPDATE provider_sync_requests SET picked_up_at = GREATEST(:at, requested_at)
             WHERE provider = :provider AND kind = 'domain_costs' AND picked_up_at IS NULL",
            ['at' => self::utc($at), 'provider' => $provider],
        );
    }

    private function time(string $sql, string $provider): ?\DateTimeImmutable
    {
        $row = $this->repository->fetchAll($sql, ['provider' => $provider])[0] ?? null;

        return $row === null || ($row['at'] ?? null) === null ? null : new \DateTimeImmutable(Row::string($row, 'at'), new \DateTimeZone('UTC'));
    }

    private static function utc(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format(self::TIME_FORMAT);
    }
}
