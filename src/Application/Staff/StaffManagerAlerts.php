<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Staff;

use Paxofi\Core\Contracts\Logger;
use PaxofiCloud\Application\Contracts\EmailOutbox;
use PaxofiCloud\Application\Contracts\StaffDirectory;
use PaxofiCloud\Application\Email\EmailAddress;
use PaxofiCloud\Domain\Staff\Permission;
use PaxofiCloud\Domain\Staff\StaffRole;

/**
 * Emails every active staff manager (anyone whose role holds staff.manage).
 * Best effort: a failure to queue one email is logged and never stops the
 * caller. Parameters are fixed codes and times, never secrets.
 */
final readonly class StaffManagerAlerts
{
    public function __construct(private StaffDirectory $directory, private EmailOutbox $outbox, private Logger $logger)
    {
    }

    /** @param array<string, string> $parameters */
    public function send(string $template, array $parameters): int
    {
        $managers = array_values(array_filter(
            StaffRole::cases(),
            static fn (StaffRole $r): bool => in_array(Permission::StaffManage, $r->permissions(), true),
        ));
        $sent = 0;
        foreach ($this->directory->activeHoldersOf($managers) as $manager) {
            try {
                $this->outbox->enqueue(new EmailAddress($manager['email']), $template, ['name' => $manager['full_name']] + $parameters);
                $sent++;
            } catch (\Throwable $e) {
                $this->logger->error('staff_alert.failed', ['template' => $template, 'error' => $e::class]);
            }
        }

        return $sent;
    }
}
