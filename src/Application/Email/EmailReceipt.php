<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Email;

/** A provider accepted the message. */
final readonly class EmailReceipt
{
    public function __construct(public string $provider, public string $messageId)
    {
    }
}
