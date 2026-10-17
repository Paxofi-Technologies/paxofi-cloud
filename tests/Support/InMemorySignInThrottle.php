<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Support;

use PaxofiCloud\Application\Contracts\SignInThrottle;
use PaxofiCloud\Infrastructure\Session\RedisSignInThrottle;

/** RedisSignInThrottle's counting rules without time (integration tests cover the TTLs). */
final class InMemorySignInThrottle implements SignInThrottle
{
    /** @var array<string, int> */
    public array $failures = [];
    /** @var array<string, int> */
    public array $locks = [];
    /** @var array<string, int> */
    public array $accountFailures = [];

    public function retryAfter(string $pair): int
    {
        return $this->locks[$pair] ?? 0;
    }

    public function recordFailure(string $pair): int
    {
        $count = $this->failures[$pair] = ($this->failures[$pair] ?? 0) + 1;
        if ($count <= RedisSignInThrottle::FREE_FAILURES) {
            return 0;
        }

        return $this->locks[$pair] = min(RedisSignInThrottle::BASE_LOCK_SECONDS * 2 ** ($count - RedisSignInThrottle::FREE_FAILURES - 1), RedisSignInThrottle::MAX_LOCK_SECONDS);
    }

    public function recordAccountFailure(string $emailLookup): int
    {
        return $this->accountFailures[$emailLookup] = ($this->accountFailures[$emailLookup] ?? 0) + 1;
    }

    public function clear(string $pair): void
    {
        unset($this->failures[$pair], $this->locks[$pair]);
    }

    public function unlock(): void
    {
        $this->locks = [];
    }
}
