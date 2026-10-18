<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Contracts;

use PaxofiCloud\Application\Audit\AuditEntry;
use PaxofiCloud\Application\Audit\AuditEvent;

/** Append-only, hash-chained security audit log (threat model D-14, §6.14). */
interface AuditLog
{
    public function append(AuditEvent $event): void;

    /** @return list<AuditEntry> newest first, events whose subject is the identity */
    public function recentForIdentity(string $identityId, int $limit): array;
}
