<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Staff\Session;

/** A new staff session: the raw ID goes into the cookie only, once. */
final readonly class IssuedStaffSession
{
    public function __construct(#[\SensitiveParameter] public string $sessionId, public string $csrfToken, public StaffSession $session)
    {
    }
}
