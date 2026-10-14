<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Email;

/** A claimed outbox row. */
final readonly class OutboxItem
{
    public function __construct(
        public string $id,
        public string $template,
        public string $recipientHash,
        public string $payload,
        public int $attempts,
    ) {
    }
}
