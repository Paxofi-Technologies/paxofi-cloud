<?php

declare(strict_types=1);

namespace PaxofiCloud\Cli;

use Paxofi\Core\Contracts\Command;
use PaxofiCloud\Application\Audit\ClientContext;
use PaxofiCloud\Application\Staff\Passkeys\StaffInvitationRefused;
use PaxofiCloud\Application\Staff\Passkeys\StaffInvitations;

/**
 * `bin/paxoficloud staff:bootstrap <email> <full name>` — invites the first
 * Security Admin (threat model §6.17). Refuses once any active staff manager
 * exists. The invitation link goes by email only: it is never printed, because
 * operator output can end up in CI logs.
 */
final readonly class StaffBootstrapCommand implements Command
{
    private const string USAGE = 'usage: staff:bootstrap <email> <full name>';

    /** @param \Closure(): StaffInvitations $invitations resolved lazily (needs the outbox key) */
    public function __construct(private \Closure $invitations, private Console $console)
    {
    }

    public function name(): string
    {
        return 'staff:bootstrap';
    }

    public function execute(array $arguments): int
    {
        if (count($arguments) !== 2) {
            $this->console->error(self::USAGE);

            return 1;
        }
        [$email, $name] = $arguments;

        try {
            $staffId = ($this->invitations)()->bootstrap($email, $name, ClientContext::from('127.0.0.1', 'paxoficloud-cli', null, null, null, 'cli-staff-bootstrap'));
        } catch (StaffInvitationRefused $e) {
            $this->console->error('staff:bootstrap refused: ' . $e->reason);

            return 2;
        }
        $this->console->line(sprintf('staff:bootstrap invitation queued staff_id=%s (link sent by email; valid 24 hours)', $staffId));

        return 0;
    }
}
