<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Email;

/**
 * Read-only view of the outbox for operators. It exposes only counts and the
 * fixed failure texts the worker stores; never recipients, hashes or payloads.
 */
interface OutboxReport
{
    /** @return array<string, int> status => number of rows */
    public function countsByStatus(): array;

    /**
     * Most recent rows that failed or are waiting to retry, newest first.
     *
     * @return list<array{template: string, status: string, attempts: int, last_error: string, updated_at: string}>
     */
    public function recentProblems(int $limit): array;
}
