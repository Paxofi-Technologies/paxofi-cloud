<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Migrations;

interface MigrationLock
{
    /** @return bool false when another runner holds the lock */
    public function acquire(): bool;

    public function release(): void;
}
