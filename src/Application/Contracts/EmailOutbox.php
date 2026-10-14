<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Contracts;

use PaxofiCloud\Application\Email\EmailAddress;

/**
 * Queues an email in the same database transaction as the action that needs
 * it (threat model E-2). Parameters may contain one-time tokens; the outbox
 * must never store them readable (E-3).
 */
interface EmailOutbox
{
    /**
     * @param array<string, string> $parameters
     * @return string the outbox ID
     */
    public function enqueue(EmailAddress $to, string $template, array $parameters): string;
}
