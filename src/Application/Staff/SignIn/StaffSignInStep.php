<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Staff\SignIn;

/** A sign-in that needs another code: the new ticket for the cookie and what to ask for next. */
final readonly class StaffSignInStep
{
    public const string TOTP = 'totp';
    public const string EMAIL = 'email';

    public function __construct(#[\SensitiveParameter] public string $ticket, public string $next, public int $expiresIn)
    {
    }
}
