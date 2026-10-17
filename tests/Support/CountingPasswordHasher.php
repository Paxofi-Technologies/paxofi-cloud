<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Support;

use Paxofi\Core\Contracts\PasswordHasher;

/** Fast stand-in that still produces an Argon2id-shaped string. */
final class CountingPasswordHasher implements PasswordHasher
{
    public int $hashes = 0;

    public function hash(string $password): string
    {
        $this->hashes++;

        return '$argon2id$test$' . hash('sha256', $password);
    }

    public function verify(string $password, string $hash): bool
    {
        return hash_equals($hash, '$argon2id$test$' . hash('sha256', $password));
    }
}
