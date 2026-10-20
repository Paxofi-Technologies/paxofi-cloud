<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Contracts;

/**
 * Sealed credentials waiting for the provider worker (PC-5). One row per
 * credential ID: a newer submission replaces a pending one.
 */
interface CredentialIntake
{
    public function replace(string $credentialId, string $provider, string $sealed, string $submittedBy, \DateTimeImmutable $now): void;

    /** @return array<string, \DateTimeImmutable> credential ID => submitted at */
    public function pending(): array;

    /** @return list<array{credential_id: string, provider: string, sealed: string, submitted_by: string}> oldest first */
    public function oldest(int $limit): array;

    public function remove(string $credentialId, string $sealed): void;
}
