<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Contracts;

/** Metadata of stored provider credentials, readable without the KEK (PC-2: never the secret). */
interface ProviderCredentialStatus
{
    /** @return array<string, array{updated_at: \DateTimeImmutable, review_due_on: \DateTimeImmutable}> credential ID => metadata */
    public function configured(): array;
}
