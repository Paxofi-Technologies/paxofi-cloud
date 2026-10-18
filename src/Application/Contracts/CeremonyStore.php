<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Contracts;

/**
 * Single-use, short-lived WebAuthn ceremony state (§6.17), keyed by the
 * SHA-256 of a random ceremony ID that only the browser holds.
 */
interface CeremonyStore
{
    /** @param array<string, string> $state */
    public function put(string $ceremonyHash, array $state, int $ttlSeconds): void;

    /**
     * Reads and deletes in one step, so a ceremony can be finished once.
     *
     * @return array<string, string>|null
     */
    public function take(string $ceremonyHash): ?array;
}
