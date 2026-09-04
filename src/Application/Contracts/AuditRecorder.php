<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Contracts;

interface AuditRecorder
{
    /** @param array<string, scalar|null> $attributes */
    public function record(string $action, string $subjectType, string $subjectId, array $attributes = []): void;
}
