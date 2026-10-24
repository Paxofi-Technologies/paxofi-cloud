<?php

declare(strict_types=1);

namespace PaxofiCloud\Cli;

use Paxofi\Core\Contracts\Command;
use PaxofiCloud\Application\Audit\ClientContext;
use PaxofiCloud\Application\Staff\FounderBootstrap;
use PaxofiCloud\Application\Staff\Passkeys\StaffInvitationRefused;
use PaxofiCloud\Application\Staff\Passkeys\StaffInvitations;
use PaxofiCloud\Application\Staff\StaffRoleChangeRefused;
use PaxofiCloud\Domain\Staff\StaffRole;

/**
 * `bin/paxoficloud staff:bootstrap <email> <full name>` — invites the first
 * Security Admin (threat model §6.17). The invitation link goes by email only:
 * it is never printed, because operator output can end up in CI logs.
 *
 * Once a staff manager exists, the same command is the founder bootstrap
 * (§6.23): if that person is the only active staff member and the email is
 * theirs, it adds the founder roles (Catalogue); anything else is refused.
 */
final readonly class StaffBootstrapCommand implements Command
{
    private const string USAGE = 'usage: staff:bootstrap <email> <full name>';

    /**
     * @param \Closure(): StaffInvitations $invitations resolved lazily (needs the outbox key)
     * @param \Closure(): FounderBootstrap $founder
     */
    public function __construct(private \Closure $invitations, private Console $console, private \Closure $founder)
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

        $client = ClientContext::from('127.0.0.1', 'paxoficloud-cli', null, null, null, 'cli-staff-bootstrap');
        try {
            $staffId = ($this->invitations)()->bootstrap($email, $name, $client);
        } catch (StaffInvitationRefused $e) {
            if ($e->reason !== StaffInvitationRefused::MANAGERS_EXIST) {
                $this->console->error('staff:bootstrap refused: ' . $e->reason);

                return 2;
            }

            return $this->founder($email, $client);
        }
        $this->console->line(sprintf('staff:bootstrap invitation queued staff_id=%s (link sent by email; valid 24 hours)', $staffId));

        return 0;
    }

    private function founder(string $email, ClientContext $client): int
    {
        try {
            $granted = ($this->founder)()->grantFounderRoles($email, $client);
        } catch (StaffRoleChangeRefused $e) {
            $this->console->error('staff:bootstrap refused: ' . $e->reason);

            return 2;
        }
        $this->console->line($granted === []
            ? 'staff:bootstrap founder already holds the founder roles'
            : 'staff:bootstrap founder roles granted: ' . implode(',', array_map(static fn (StaffRole $r): string => $r->value, $granted)));

        return 0;
    }
}
