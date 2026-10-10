<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Health;

use Paxofi\Core\Contracts\HealthCheck;
use Paxofi\Core\Contracts\Repository;
use Paxofi\Core\Observability\HealthResult;
use PaxofiCloud\Infrastructure\Persistence\Row;

final readonly class DatabaseHealthCheck implements HealthCheck
{
    public function __construct(private Repository $repository)
    {
    }

    public function name(): string
    {
        return 'database';
    }

    public function check(): HealthResult
    {
        $rows = $this->repository->fetchAll('SELECT 1 AS ok');

        return isset($rows[0]) && Row::int($rows[0], 'ok') === 1
            ? HealthResult::healthy()
            : HealthResult::unhealthy('Unexpected response to SELECT 1');
    }
}
