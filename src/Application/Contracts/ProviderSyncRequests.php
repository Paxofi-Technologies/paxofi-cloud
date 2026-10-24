<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Contracts;

/**
 * "Sync now" requests from the admin console (THREAT-MODEL-DOMAIN-REGISTRAR.md
 * DR-12). The web tier adds; the provider worker claims. Never deleted.
 */
interface ProviderSyncRequests
{
    public function add(string $provider, string $staffId, \DateTimeImmutable $at): void;

    /** The newest request for this provider, claimed or not; null if there has never been one. */
    public function lastRequestedAt(string $provider): ?\DateTimeImmutable;

    /** The oldest request nobody has claimed yet, or null. */
    public function pendingSince(string $provider): ?\DateTimeImmutable;

    /** Marks every unclaimed request for this provider as claimed in one statement; returns how many. */
    public function claimAll(string $provider, \DateTimeImmutable $at): int;
}
