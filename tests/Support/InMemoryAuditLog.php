<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Support;

use PaxofiCloud\Application\Audit\AuditEntry;
use PaxofiCloud\Application\Audit\AuditEvent;
use PaxofiCloud\Application\Contracts\AuditLog;

final class InMemoryAuditLog implements AuditLog
{
    /** @var list<AuditEvent> */
    public array $events = [];

    public bool $fail = false;

    public function append(AuditEvent $event): void
    {
        if ($this->fail) {
            throw new \RuntimeException('audit store down');
        }
        $this->events[] = $event;
    }

    public function recentForIdentity(string $identityId, int $limit): array
    {
        $entries = [];
        foreach (array_reverse($this->events) as $i => $event) {
            if ($event->identityId === $identityId && count($entries) < $limit) {
                $entries[] = new AuditEntry(
                    sprintf('%032x', $i),
                    $event->occurredAt,
                    $event->type,
                    $event->outcome,
                    $event->details,
                    $event->client->ip,
                    $event->client->country,
                    $event->client->region,
                    $event->client->city,
                    $event->client->device(),
                );
            }
        }

        return $entries;
    }

    /** @return list<string> event types in order */
    public function types(): array
    {
        return array_map(static fn (AuditEvent $e): string => $e->type, $this->events);
    }
}
