<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Identity\Session;

/** The raw session ID (for the cookie) and CSRF token (for the client), returned once. */
final readonly class IssuedSession
{
    public function __construct(
        #[\SensitiveParameter] public string $sessionId,
        #[\SensitiveParameter] public string $csrfToken,
        public Session $session,
    ) {
    }
}
