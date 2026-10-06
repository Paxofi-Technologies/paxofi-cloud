<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Time;

use PaxofiCloud\Application\Contracts\Clock;

/** Wall-clock time in UTC. */
final readonly class SystemClock implements Clock
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
