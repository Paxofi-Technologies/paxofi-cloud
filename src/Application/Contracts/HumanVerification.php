<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Contracts;

/** Bot challenge verified on the server (threat model T-02, §6.12). */
interface HumanVerification
{
    /** False when no challenge is configured (never in production). */
    public function enabled(): bool;

    /**
     * @param string|null $token the widget's response token, as posted by the client
     * @param string $action the form the token must have been issued for
     */
    public function verify(#[\SensitiveParameter] ?string $token, string $action, string $clientIp): HumanCheck;
}
