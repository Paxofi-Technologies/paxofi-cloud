<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Email\Smtp;

/** A complete (possibly multi-line) SMTP reply. The text is used to read capabilities only; it is never logged. */
final readonly class SmtpReply
{
    /** @param list<string> $lines text after the code on each line */
    public function __construct(public int $code, public array $lines)
    {
    }
}
