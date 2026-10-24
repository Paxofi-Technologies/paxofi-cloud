<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Contracts;

use PaxofiCloud\Application\Audit\AuditLogQuery;
use PaxofiCloud\Application\Audit\AuditRecord;

/** Read-only staff search of the audit log (identity threat model §6.24). */
interface AuditLogSearch
{
    /** @return list<AuditRecord> newest first, at most $limit */
    public function search(AuditLogQuery $query, int $limit): array;

    /** @return array{seq: int, hash: string} the chain head, the manual integrity anchor */
    public function head(): array;
}
