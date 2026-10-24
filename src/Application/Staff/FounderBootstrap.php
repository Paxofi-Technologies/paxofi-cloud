<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Staff;

use Paxofi\Core\Contracts\TransactionManager;
use PaxofiCloud\Application\Audit\AuditEvent;
use PaxofiCloud\Application\Audit\AuditEventType;
use PaxofiCloud\Application\Audit\AuditTrail;
use PaxofiCloud\Application\Audit\ClientContext;
use PaxofiCloud\Application\Contracts\Clock;
use PaxofiCloud\Application\Contracts\EmailOutbox;
use PaxofiCloud\Application\Contracts\StaffDirectory;
use PaxofiCloud\Application\Email\EmailAddress;
use PaxofiCloud\Application\Email\EmailTemplates;
use PaxofiCloud\Domain\Staff\StaffRole;

/**
 * Founder bootstrap (threat model §6.23; CEO decision 24 Oct 2026). While the
 * founding Security Admin is the only active staff member, nobody else can
 * grant them a role, so this gives them the founder roles directly. It works
 * only when exactly one staff member is active, holds Security Admin and has
 * the given email; it closes by itself once a second staff member is active.
 */
final readonly class FounderBootstrap
{
    /** Never Super Admin. */
    public const array FOUNDER_ROLES = [StaffRole::Catalogue];

    public function __construct(
        private StaffDirectory $directory,
        private TransactionManager $transactions,
        private Clock $clock,
        private AuditTrail $audit,
        private EmailOutbox $outbox,
    ) {
    }

    /**
     * @return list<StaffRole> the roles newly granted (empty when the founder already had them)
     * @throws StaffRoleChangeRefused not_sole_founder | founder_mismatch
     */
    public function grantFounderRoles(string $email, ClientContext $client): array
    {
        try {
            $address = new EmailAddress($email);
        } catch (\InvalidArgumentException) {
            $this->refuse(StaffRoleChangeRefused::FOUNDER_MISMATCH, $client);
        }

        try {
            [$founder, $granted] = $this->transactions->transaction(function () use ($address): array {
                // Locks every active grant, so a concurrent activation or grant waits for us.
                $this->directory->countActiveHoldersForUpdate(StaffRole::cases());
                $active = array_values(array_filter($this->directory->members(), static fn (array $m): bool => $m['status'] === 'active'));
                if (count($active) !== 1) {
                    throw new StaffRoleChangeRefused(StaffRoleChangeRefused::NOT_SOLE_FOUNDER);
                }
                $founder = $active[0];
                // Same case-insensitive comparison as staff uniqueness (EmailAddress::hash).
                if (!hash_equals($address->hash(), (new EmailAddress($founder['email']))->hash())
                    || !in_array(StaffRole::SecurityAdmin, $founder['roles'], true)) {
                    throw new StaffRoleChangeRefused(StaffRoleChangeRefused::FOUNDER_MISMATCH);
                }
                $granted = [];
                foreach (self::FOUNDER_ROLES as $role) {
                    if (!$this->directory->hasOpenGrant($founder['id'], $role)) {
                        $this->directory->addGrant(bin2hex(random_bytes(16)), $founder['id'], $role, StaffRoles::ACTIVE, null, $this->clock->now());
                        $granted[] = $role;
                    }
                }

                return [$founder, $granted];
            });
        } catch (StaffRoleChangeRefused $e) {
            $this->refuse($e->reason, $client);
        }

        foreach ($granted as $role) {
            $this->audit->record(AuditEventType::STAFF_ROLE_GRANTED, AuditEvent::SUCCESS, $client, details: [
                'subject' => $founder['id'],
                'role' => $role->value,
                'via' => 'founder_bootstrap',
            ]);
        }
        if ($granted !== []) {
            try {
                $this->outbox->enqueue(new EmailAddress($founder['email']), EmailTemplates::STAFF_ROLES_ADDED, [
                    'name' => $founder['full_name'],
                    'roles' => implode(',', array_map(static fn (StaffRole $r): string => $r->value, $granted)),
                ]);
            } catch (\Throwable) {
                // The grant stands and is audited; the notice is best effort.
            }
        }

        return $granted;
    }

    /** @return never */
    private function refuse(string $reason, ClientContext $client): never
    {
        $this->audit->record(AuditEventType::STAFF_ROLE_CHANGE_REFUSED, AuditEvent::FAILURE, $client, details: ['via' => 'founder_bootstrap', 'reason' => $reason]);

        throw new StaffRoleChangeRefused($reason);
    }
}
