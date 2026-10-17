<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Contracts;

/** Known-breached password lookup (SRS IAM-003; threat model T-06). */
interface BreachedPasswordCheck
{
    /** @return bool|null true = seen in a breach, false = not seen, null = could not check (callers fail open) */
    public function isBreached(#[\SensitiveParameter] string $password): ?bool;
}
