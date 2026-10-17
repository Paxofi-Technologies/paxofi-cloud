<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Contracts;

interface RateLimiter
{
    /**
     * Counts one attempt against `$key` in a fixed window.
     *
     * @return int 0 when allowed, otherwise the seconds until the window resets
     */
    public function hit(string $key, int $limit, int $windowSeconds): int;
}
