<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Contracts;

/**
 * Failure counters for sign-in (threat model T-07, T-08). A "pair" is one
 * email address from one client network; locking it never affects the owner
 * signing in from elsewhere.
 */
interface SignInThrottle
{
    /** Seconds until the pair may try again; 0 when it is not locked. */
    public function retryAfter(string $pair): int;

    /** Counts a failure; returns the lock (seconds) it caused, 0 for none. */
    public function recordFailure(string $pair): int;

    /** Counts a failure for the address across all networks, known or not; returns the hourly total. */
    public function recordAccountFailure(string $emailLookup): int;

    /**
     * Current failures for the pair (15 min) and for the address across all
     * networks (hour), for deciding whether a challenge is needed (§6.12).
     *
     * @return array{pair: int, account: int}
     */
    public function failures(string $pair, string $emailLookup): array;

    public function clear(string $pair): void;
}
