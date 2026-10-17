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

    /** Counts a failure for the account across all networks; returns the hourly total. */
    public function recordAccountFailure(string $emailLookup): int;

    public function clear(string $pair): void;
}
