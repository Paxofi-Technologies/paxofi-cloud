<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Support;

use Paxofi\Core\Contracts\Connection;
use Paxofi\Core\Contracts\Repository;
use PaxofiCloud\Infrastructure\Migrations\MigrationLock;
use RuntimeException;

/**
 * Fakes just enough of MySQL for Migrator unit tests: it records executed
 * statements and keeps schema_migrations rows in memory.
 */
final class InMemoryMigrationDatabase implements Connection, Repository, MigrationLock
{
    /** @var list<string> */
    public array $executed = [];

    /** @var array<string, array{version: string, name: string, checksum: string, applied_at: string}> */
    public array $rows = [];

    public bool $lockAvailable = true;
    public bool $locked = false;
    public ?string $failOn = null;

    public function beginTransaction(): void
    {
    }

    public function commit(): void
    {
    }

    public function rollBack(): void
    {
    }

    public function execute(string $sql, array $parameters = []): int
    {
        if ($this->failOn !== null && str_contains($sql, $this->failOn)) {
            throw new RuntimeException('Simulated SQL error');
        }
        if (str_starts_with($sql, 'INSERT INTO schema_migrations')) {
            $version = $parameters['version'];
            $name = $parameters['name'];
            $checksum = $parameters['checksum'];
            if (!is_string($version) || !is_string($name) || !is_string($checksum)) {
                throw new RuntimeException('Unexpected parameter types');
            }
            $this->rows[$version] = ['version' => $version, 'name' => $name, 'checksum' => $checksum, 'applied_at' => '2026-10-05 12:00:00.000000'];

            return 1;
        }
        if (!str_starts_with($sql, 'CREATE TABLE IF NOT EXISTS schema_migrations')) {
            $this->executed[] = $sql;
        }

        return 0;
    }

    public function fetchAll(string $sql, array $parameters = []): array
    {
        return array_values($this->rows);
    }

    public function acquire(): bool
    {
        $this->locked = $this->lockAvailable;

        return $this->lockAvailable;
    }

    public function release(): void
    {
        $this->locked = false;
    }
}
