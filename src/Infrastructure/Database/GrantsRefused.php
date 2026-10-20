<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Database;

/** The runtime grants could not be applied safely (§6.22); the release must stop. */
final class GrantsRefused extends \RuntimeException
{
}
