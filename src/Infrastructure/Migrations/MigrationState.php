<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Migrations;

enum MigrationState: string
{
    case Applied = 'applied';
    case Pending = 'pending';
    /** Applied, but the file changed afterwards. */
    case Modified = 'modified';
    /** Recorded as applied, but the file no longer exists. */
    case Missing = 'missing';
}
