<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Staff\Session;

/** A signed-in staff member's server-side session (§6.18). */
final readonly class StaffSession
{
    public function __construct(
        public string $idHash,
        public string $staffId,
        public string $csrfToken,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $lastSeenAt,
    ) {
    }

    public function seenAt(\DateTimeImmutable $now): self
    {
        return new self($this->idHash, $this->staffId, $this->csrfToken, $this->createdAt, $now);
    }
}
