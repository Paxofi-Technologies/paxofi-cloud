<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Migrations;

use Paxofi\Core\Contracts\Repository;
use PaxofiCloud\Infrastructure\Persistence\Row;

/**
 * MySQL named lock (GET_LOCK), held by the same session that runs the
 * migrations, so two deploys can never migrate concurrently.
 */
final readonly class MysqlAdvisoryLock implements MigrationLock
{
    public function __construct(
        private Repository $repository,
        private string $name = 'paxoficloud:migrate',
        private int $timeoutSeconds = 10,
    ) {
    }

    public function acquire(): bool
    {
        $rows = $this->repository->fetchAll(
            'SELECT GET_LOCK(:name, :timeout) AS acquired',
            ['name' => $this->name, 'timeout' => $this->timeoutSeconds],
        );

        return isset($rows[0]) && ($rows[0]['acquired'] ?? null) !== null && Row::int($rows[0], 'acquired') === 1;
    }

    public function release(): void
    {
        $this->repository->fetchAll('SELECT RELEASE_LOCK(:name) AS released', ['name' => $this->name]);
    }
}
