<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Migrations;

use Paxofi\Core\Contracts\Connection;
use Paxofi\Core\Contracts\Repository;
use PaxofiCloud\Infrastructure\Persistence\Row;
use Throwable;

/**
 * Forward-only migration runner (SRS OPS-007).
 *
 * Before applying anything it verifies history: every applied migration must
 * still exist with an identical checksum, and no pending migration may be
 * numbered below the newest applied one. Any violation stops the run.
 *
 * MySQL commits DDL implicitly, so a migration is not atomic. Keep one schema
 * change per file; a failed file is reported and must be fixed by a new
 * migration, never by editing an applied one.
 */
final readonly class Migrator
{
    public const string TABLE = 'schema_migrations';

    public function __construct(
        private Connection $connection,
        private Repository $repository,
        private MigrationLock $lock,
        private SqlStatementSplitter $splitter = new SqlStatementSplitter(),
    ) {
    }

    /**
     * @param list<Migration> $migrations
     * @return list<array{version: string, name: string, state: MigrationState, applied_at: ?string}>
     */
    public function status(array $migrations): array
    {
        $this->ensureTable();
        $applied = $this->applied();
        $rows = [];

        foreach ($migrations as $migration) {
            $record = $applied[$migration->number()] ?? null;
            $state = match (true) {
                $record === null => MigrationState::Pending,
                $record['checksum'] !== $migration->checksum => MigrationState::Modified,
                default => MigrationState::Applied,
            };
            $rows[$migration->number()] = [
                'version' => $migration->version,
                'name' => $migration->name,
                'state' => $state,
                'applied_at' => $record['applied_at'] ?? null,
            ];
        }

        foreach ($applied as $number => $record) {
            if (!isset($rows[$number])) {
                $rows[$number] = [
                    'version' => $record['version'],
                    'name' => $record['name'],
                    'state' => MigrationState::Missing,
                    'applied_at' => $record['applied_at'],
                ];
            }
        }

        ksort($rows);

        return array_values($rows);
    }

    /**
     * @param list<Migration> $migrations
     * @param (callable(Migration, int): void)|null $onApplied receives the migration and its duration in ms
     * @return list<Migration> the migrations applied by this run
     */
    public function migrate(array $migrations, ?callable $onApplied = null): array
    {
        if (!$this->lock->acquire()) {
            throw new MigrationError('Another migration run holds the lock; try again later.');
        }

        try {
            $this->ensureTable();
            $pending = $this->verifyAndFindPending($migrations);

            $done = [];
            foreach ($pending as $migration) {
                $milliseconds = $this->apply($migration);
                $done[] = $migration;
                if ($onApplied !== null) {
                    $onApplied($migration, $milliseconds);
                }
            }

            return $done;
        } finally {
            $this->lock->release();
        }
    }

    /**
     * @param list<Migration> $migrations
     * @return list<Migration>
     */
    private function verifyAndFindPending(array $migrations): array
    {
        $applied = $this->applied();
        $onDisk = [];
        foreach ($migrations as $migration) {
            $onDisk[$migration->number()] = $migration;
        }

        foreach ($applied as $number => $record) {
            $file = $onDisk[$number] ?? null;
            if ($file === null) {
                throw new MigrationError(sprintf('Applied migration %s (%s) is missing from disk.', $record['version'], $record['name']));
            }
            if ($file->checksum !== $record['checksum']) {
                throw new MigrationError(sprintf(
                    'Migration %s (%s) was modified after it was applied. Write a new migration instead.',
                    $record['version'],
                    $record['name'],
                ));
            }
        }

        $newest = $applied === [] ? 0 : max(array_keys($applied));
        $pending = [];
        foreach ($onDisk as $number => $migration) {
            if (isset($applied[$number])) {
                continue;
            }
            if ($number < $newest) {
                throw new MigrationError(sprintf(
                    'Migration %s is older than the newest applied migration; renumber it above %d.',
                    $migration->version,
                    $newest,
                ));
            }
            $pending[] = $migration;
        }

        return $pending;
    }

    private function apply(Migration $migration): int
    {
        $started = hrtime(true);
        $statements = $this->splitter->split($migration->sql);
        if ($statements === []) {
            throw new MigrationError(sprintf('Migration %s contains no statements.', $migration->version));
        }

        foreach ($statements as $index => $statement) {
            try {
                $this->connection->execute($statement);
            } catch (Throwable $exception) {
                throw new MigrationError(sprintf(
                    'Migration %s (%s) failed at statement %d of %d: %s',
                    $migration->version,
                    $migration->name,
                    $index + 1,
                    count($statements),
                    $exception->getMessage(),
                ), 0, $exception);
            }
        }

        $milliseconds = intdiv(hrtime(true) - $started, 1_000_000);
        $this->connection->execute(
            'INSERT INTO ' . self::TABLE . ' (version, name, checksum, applied_at, execution_ms)'
            . ' VALUES (:version, :name, :checksum, UTC_TIMESTAMP(6), :ms)',
            ['version' => $migration->version, 'name' => $migration->name, 'checksum' => $migration->checksum, 'ms' => $milliseconds],
        );

        return $milliseconds;
    }

    private function ensureTable(): void
    {
        $this->connection->execute(
            'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' ('
            . ' version VARCHAR(20) NOT NULL PRIMARY KEY,'
            . ' name VARCHAR(190) NOT NULL,'
            . ' checksum CHAR(64) NOT NULL,'
            . ' applied_at DATETIME(6) NOT NULL,'
            . ' execution_ms INT UNSIGNED NOT NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci',
        );
    }

    /** @return array<int, array{version: string, name: string, checksum: string, applied_at: string}> */
    private function applied(): array
    {
        $applied = [];
        foreach ($this->repository->fetchAll('SELECT version, name, checksum, applied_at FROM ' . self::TABLE) as $row) {
            $version = Row::string($row, 'version');
            $applied[(int) $version] = [
                'version' => $version,
                'name' => Row::string($row, 'name'),
                'checksum' => Row::string($row, 'checksum'),
                'applied_at' => Row::string($row, 'applied_at'),
            ];
        }

        return $applied;
    }
}
