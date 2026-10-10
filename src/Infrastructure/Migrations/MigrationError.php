<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Migrations;

use RuntimeException;

/** Raised when migrations cannot run safely. Nothing is applied after one. */
final class MigrationError extends RuntimeException
{
}
