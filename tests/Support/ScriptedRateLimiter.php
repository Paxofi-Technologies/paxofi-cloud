<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Support;

use PaxofiCloud\Application\Contracts\RateLimiter;

/** Counts hits per key like a fixed window that never resets. */
final class ScriptedRateLimiter implements RateLimiter
{
    /** @var array<string, int> */
    public array $hits = [];

    public function hit(string $key, int $limit, int $windowSeconds): int
    {
        $this->hits[$key] = ($this->hits[$key] ?? 0) + 1;

        return $this->hits[$key] > $limit ? $windowSeconds : 0;
    }
}
