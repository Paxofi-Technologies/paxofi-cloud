<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Email;

/** Worker-side outbox persistence. Final states purge the sealed payload (E-3). */
interface OutboxStore
{
    /**
     * Atomically claims due rows (pending and due, or `sending` with an expired
     * lock after a worker crash) for this worker (threat model E-5).
     *
     * @return list<OutboxItem>
     */
    public function claim(int $limit, \DateTimeImmutable $now, int $lockSeconds): array;

    public function markSent(string $id, string $provider, string $messageId, \DateTimeImmutable $now): void;

    public function reschedule(string $id, int $attempts, \DateTimeImmutable $nextAttemptAt, string $error): void;

    public function markDead(string $id, int $attempts, string $reason): void;

    public function sentSince(\DateTimeImmutable $since): int;
}
