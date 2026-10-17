<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Security;

use PaxofiCloud\Application\Contracts\BreachedPasswordCheck;

/** BREACHED_PASSWORD_CHECK=off (never in production): reports "could not check". */
final readonly class DisabledBreachedPasswordCheck implements BreachedPasswordCheck
{
    public function isBreached(#[\SensitiveParameter] string $password): ?bool
    {
        return null;
    }
}
