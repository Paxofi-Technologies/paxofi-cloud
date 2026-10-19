<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Identity\Session;

/** One signed-in customer session. Keyed by the session ID's hash; the raw ID is never stored. */
final readonly class Session
{
    public function __construct(
        public string $idHash,
        public string $identityId,
        public string $accountId,
        #[\SensitiveParameter] public string $csrfToken,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $lastSeenAt,
        public string $clientIp,
        public string $userAgent,
        public string $location = '',
    ) {
    }

    /** §6.21: the name the session list uses for this session; not the store key. */
    public function publicId(): string
    {
        return substr(hash('sha256', 'session-list|' . $this->idHash), 0, 32);
    }

    public function seenAt(\DateTimeImmutable $now): self
    {
        return new self($this->idHash, $this->identityId, $this->accountId, $this->csrfToken, $this->createdAt, $now, $this->clientIp, $this->userAgent, $this->location);
    }
}
