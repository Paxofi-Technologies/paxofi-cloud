<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Contracts;

use PaxofiCloud\Application\Staff\Session\StaffSession;

/** Staff sessions keyed by SHA-256 of the session ID, apart from customer sessions (D-6, §6.18). */
interface StaffSessionStore
{
    public function save(StaffSession $session, int $ttlSeconds): void;

    public function find(string $sessionIdHash): ?StaffSession;

    public function delete(string $sessionIdHash): void;

    /**
     * §6.25: the member's stored sessions (expired ones may still be
     * included; the caller checks the times). Index entries without a record
     * are dropped.
     *
     * @return list<StaffSession>
     */
    public function forStaff(string $staffId): array;
}
