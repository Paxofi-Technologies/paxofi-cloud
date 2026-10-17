<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Email;

use Paxofi\Core\Contracts\Repository;
use PaxofiCloud\Infrastructure\Persistence\Row;

final readonly class MysqlOutboxReport implements OutboxReport
{
    public const int MAX_PROBLEMS = 20;

    public function __construct(private Repository $repository)
    {
    }

    public function countsByStatus(): array
    {
        $counts = ['pending' => 0, 'sending' => 0, 'sent' => 0, 'dead' => 0];
        foreach ($this->repository->fetchAll('SELECT status, COUNT(*) AS n FROM email_outbox GROUP BY status') as $row) {
            $counts[Row::string($row, 'status')] = Row::int($row, 'n');
        }

        return $counts;
    }

    public function recentProblems(int $limit): array
    {
        $rows = $this->repository->fetchAll(
            'SELECT template, status, attempts, last_error, updated_at FROM email_outbox
             WHERE last_error IS NOT NULL AND status IN (\'pending\', \'sending\', \'dead\')
             ORDER BY updated_at DESC, id LIMIT ' . self::MAX_PROBLEMS,
        );
        $problems = [];
        foreach (array_slice($rows, 0, max(1, $limit)) as $row) {
            $problems[] = [
                'template' => Row::string($row, 'template'),
                'status' => Row::string($row, 'status'),
                'attempts' => Row::int($row, 'attempts'),
                'last_error' => Row::string($row, 'last_error'),
                'updated_at' => Row::string($row, 'updated_at'),
            ];
        }

        return $problems;
    }
}
