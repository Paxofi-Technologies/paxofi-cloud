<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Contracts;

/** A named lock held by one process at a time, e.g. one sync per provider (DR-10). */
interface ExclusiveLock
{
    /** Returns false at once when another process holds the lock. */
    public function acquire(string $name): bool;

    public function release(string $name): void;
}
