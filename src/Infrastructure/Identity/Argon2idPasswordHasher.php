<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Identity;

use Paxofi\Core\Contracts\PasswordHasher;

/**
 * Argon2id with explicit parameters (threat model D-3). Replaces PCF's
 * NativePasswordHasher, whose PASSWORD_DEFAULT is bcrypt: wrong algorithm for
 * D-3, and bcrypt ignores everything after 72 bytes while IAM-003 allows 128
 * characters.
 */
final readonly class Argon2idPasswordHasher implements PasswordHasher
{
    public const int MEMORY_COST_KIB = 65_536;
    public const int TIME_COST = 3;
    public const int THREADS = 1;

    /** @var array{memory_cost: int, time_cost: int, threads: int} */
    private array $options;

    /** Lower costs are for tests only; production wiring uses the defaults. */
    public function __construct(int $memoryCostKib = self::MEMORY_COST_KIB, int $timeCost = self::TIME_COST)
    {
        if (!defined('PASSWORD_ARGON2ID')) {
            throw new \LogicException('PHP was built without Argon2id support.');
        }
        $this->options = ['memory_cost' => $memoryCostKib, 'time_cost' => $timeCost, 'threads' => self::THREADS];
    }

    public function hash(#[\SensitiveParameter] string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID, $this->options);
    }

    public function verify(#[\SensitiveParameter] string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /** Called after each successful sign-in (D-3). */
    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_ARGON2ID, $this->options);
    }
}
