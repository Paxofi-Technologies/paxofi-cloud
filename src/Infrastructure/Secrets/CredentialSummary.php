<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Secrets;

/** What may be shown about a stored credential: metadata only, never the envelope or the secret. */
final readonly class CredentialSummary
{
    public function __construct(
        public StoredCredential $credential,
        public string $keyId,
        public \DateTimeImmutable $reviewDueOn,
    ) {
    }

    public function isReviewOverdue(\DateTimeImmutable $today): bool
    {
        return $this->reviewDueOn->format('Y-m-d') < $today->format('Y-m-d');
    }
}
