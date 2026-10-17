<?php

declare(strict_types=1);

namespace PaxofiCloud\Cli;

use Paxofi\Core\Contracts\Command;
use PaxofiCloud\Infrastructure\Email\OutboxSealer;

/**
 * `bin/paxoficloud mail:keygen` — prints a fresh outbox key pair as env lines.
 * Run by the deploy agent on the host on first release; the output goes
 * straight into mode-0600 env files and is never logged (threat model E-3).
 */
final readonly class MailKeygenCommand implements Command
{
    public function __construct(private Console $console)
    {
    }

    public function name(): string
    {
        return 'mail:keygen';
    }

    public function execute(array $arguments): int
    {
        $pair = OutboxSealer::generateKeyPair();
        $this->console->line('EMAIL_OUTBOX_PUBLIC_KEY=' . $pair['public']);
        $this->console->line('EMAIL_OUTBOX_SECRET_KEY=' . $pair['secret']);

        return 0;
    }
}
