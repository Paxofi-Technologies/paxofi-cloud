<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Security;

use PaxofiCloud\Application\Contracts\HumanCheck;
use PaxofiCloud\Application\Contracts\HumanVerification;

/** No challenge configured (tests, local development). Refused in production. */
final readonly class DisabledHumanVerification implements HumanVerification
{
    public function enabled(): bool
    {
        return false;
    }

    public function verify(#[\SensitiveParameter] ?string $token, string $action, string $clientIp): HumanCheck
    {
        return HumanCheck::Passed;
    }
}
