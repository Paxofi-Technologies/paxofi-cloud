<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Contracts;

/** Whether a stored hash was made with older parameters (threat model D-3). */
interface PasswordRehashPolicy
{
    public function needsRehash(string $hash): bool;
}
