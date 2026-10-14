<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Email;

/** A rendered transactional email, ready for a provider. */
final readonly class EmailMessage
{
    public function __construct(
        public EmailAddress $to,
        public string $subject,
        public string $textBody,
        public string $htmlBody,
        public string $tag,
        public string $idempotencyKey,
    ) {
        if ($subject === '' || strlen($subject) > 200 || preg_match('/[\r\n]/', $subject) === 1) {
            throw new \InvalidArgumentException('Email subject must be one non-empty line of at most 200 bytes.');
        }
        if (preg_match('/^[a-z0-9-]{1,64}$/', $tag) !== 1 || preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $idempotencyKey) !== 1) {
            throw new \InvalidArgumentException('Email tag or idempotency key is malformed.');
        }
    }
}
