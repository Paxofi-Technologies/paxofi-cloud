<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Support;

use PaxofiCloud\Application\Audit\AuditEntry;
use PaxofiCloud\Application\Audit\AuditEvent;
use PaxofiCloud\Application\Audit\AuditLogQuery;
use PaxofiCloud\Application\Audit\AuditRecord;
use PaxofiCloud\Application\Contracts\AuditLog;
use PaxofiCloud\Application\Contracts\AuditLogSearch;

/** Sequence numbers start at 1 in append order, as in MySQL. */
final class InMemoryAuditLog implements AuditLog, AuditLogSearch
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

    public function search(AuditLogQuery $query, int $limit): array
    {
        $records = [];
        for ($i = count($this->events) - 1; $i >= 0 && count($records) < $limit; $i--) {
            $event = $this->events[$i];
            $seq = $i + 1;
            $matches = ($query->beforeSeq === null || $seq < $query->beforeSeq)
                && ($query->outcome === null || $event->outcome === $query->outcome)
                && ($query->group === null || str_starts_with($event->type, $query->group . '.'))
                && ($query->subject === null || in_array($query->subject, [$event->identityId, $event->accountId, $event->details['subject'] ?? null], true));
            if (!$matches) {
                continue;
            }
            $hasContext = $event->client->ip !== '' || $event->client->userAgent !== '';
            $records[] = new AuditRecord(
                $seq,
                $event->occurredAt,
                $event->type,
                $event->outcome,
                $event->identityId,
                $event->accountId,
                $event->details,
                $hasContext ? $event->client->ip : null,
                $hasContext ? $event->client->network() : null,
                $event->client->country,
                $event->client->region,
                $event->client->city,
                $hasContext ? $event->client->userAgent : null,
                $hasContext ? $event->client->device() : null,
            );
        }

        return $records;
    }

    public function head(): array
    {
        return ['seq' => count($this->events), 'hash' => str_repeat('a', 64)];
    }

    /** @return list<string> event types in order */
    public function types(): array
    {
        return array_map(static fn (AuditEvent $e): string => $e->type, $this->events);
    }
}
