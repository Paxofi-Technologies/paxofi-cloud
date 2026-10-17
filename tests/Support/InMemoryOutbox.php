<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Support;

use PaxofiCloud\Application\Contracts\EmailOutbox;
use PaxofiCloud\Application\Email\EmailAddress;
use PaxofiCloud\Infrastructure\Email\OutboxItem;
use PaxofiCloud\Infrastructure\Email\OutboxSealer;
use PaxofiCloud\Infrastructure\Email\OutboxStore;

/** Mirrors MysqlEmailOutbox's state machine in memory for worker tests. */
final class InMemoryOutbox implements EmailOutbox, OutboxStore
{
    /** @var array<string, array{template: string, recipient_hash: string, payload: ?string, status: string, attempts: int, next: \DateTimeImmutable, sent_at: ?\DateTimeImmutable, provider: ?string, error: ?string}> */
    public array $rows = [];

    public function __construct(private readonly OutboxSealer $sealer, private readonly \PaxofiCloud\Application\Contracts\Clock $clock)
    {
    }

    public function enqueue(EmailAddress $to, string $template, array $parameters): string
    {
        $id = bin2hex(random_bytes(16));
        $this->rows[$id] = [
            'template' => $template,
            'recipient_hash' => $to->hash(),
            'payload' => $this->sealer->seal(json_encode(['id' => $id, 'template' => $template, 'to' => $to->value, 'parameters' => $parameters], JSON_THROW_ON_ERROR)),
            'status' => 'pending',
            'attempts' => 0,
            'next' => $this->clock->now(),
            'sent_at' => null,
            'provider' => null,
            'error' => null,
        ];

        return $id;
    }

    public function claim(int $limit, \DateTimeImmutable $now, int $lockSeconds): array
    {
        $items = [];
        foreach ($this->rows as $id => &$row) {
            if (count($items) >= $limit) {
                break;
            }
            if ($row['status'] === 'pending' && $row['next'] <= $now && $row['payload'] !== null) {
                $row['status'] = 'sending';
                $items[] = new OutboxItem($id, $row['template'], $row['recipient_hash'], $row['payload'], $row['attempts']);
            }
        }

        return $items;
    }

    public function markSent(string $id, string $provider, string $messageId, \DateTimeImmutable $now): void
    {
        $this->rows[$id] = ['status' => 'sent', 'payload' => null, 'attempts' => $this->rows[$id]['attempts'] + 1, 'sent_at' => $now, 'provider' => $provider] + $this->rows[$id];
    }

    public function reschedule(string $id, int $attempts, \DateTimeImmutable $nextAttemptAt, string $error): void
    {
        $this->rows[$id] = ['status' => 'pending', 'attempts' => $attempts, 'next' => $nextAttemptAt, 'error' => $error] + $this->rows[$id];
    }

    public function markDead(string $id, int $attempts, string $reason): void
    {
        $this->rows[$id] = ['status' => 'dead', 'payload' => null, 'attempts' => $attempts, 'error' => $reason] + $this->rows[$id];
    }

    public function sentSince(\DateTimeImmutable $since): int
    {
        return count(array_filter($this->rows, static fn (array $r): bool => $r['sent_at'] !== null && $r['sent_at'] >= $since));
    }

    /** Simulates an attacker with database write access copying a sealed payload onto another row. */
    public function movePayload(string $fromId, string $toId): void
    {
        $this->rows[$toId]['payload'] = $this->rows[$fromId]['payload'];
        $this->markSent($fromId, 'postmark', 'm', new \DateTimeImmutable());
    }
}
