<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Staff\Session;

/** A signed-in staff member's server-side session (§6.18), with where it was opened (§6.25). */
final readonly class StaffSession
{
    public function __construct(
        public string $idHash,
        public string $staffId,
        #[\SensitiveParameter] public string $csrfToken,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $lastSeenAt,
        public string $clientIp = '',
        public string $userAgent = '',
        public string $location = '',
    ) {
    }

    /** §6.25: the name the session list uses for this session; not the store key. */
    public function publicId(): string
    {
        return substr(hash('sha256', 'staff-session-list|' . $this->idHash), 0, 32);
    }

    public function seenAt(\DateTimeImmutable $now): self
    {
        return new self($this->idHash, $this->staffId, $this->csrfToken, $this->createdAt, $now, $this->clientIp, $this->userAgent, $this->location);
    }
}
