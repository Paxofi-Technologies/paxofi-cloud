<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Contracts;

use PaxofiCloud\Application\Identity\Session\Session;

/** Server-side sessions keyed by SHA-256 of the session ID (threat model D-2, D-6). */
interface SessionStore
{
    public function save(Session $session, int $ttlSeconds): void;

    public function find(string $sessionIdHash): ?Session;

    public function delete(string $sessionIdHash): void;
}
