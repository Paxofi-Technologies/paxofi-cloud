<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Staff\Passkeys;

use Paxofi\Core\Contracts\TransactionManager;
use PaxofiCloud\Application\Audit\AuditEvent;
use PaxofiCloud\Application\Audit\AuditEventType;
use PaxofiCloud\Application\Audit\AuditTrail;
use PaxofiCloud\Application\Audit\ClientContext;
use PaxofiCloud\Application\Contracts\Clock;
use PaxofiCloud\Application\Contracts\EmailOutbox;
use PaxofiCloud\Application\Contracts\StaffDirectory;
use PaxofiCloud\Application\Contracts\StaffPasskeyStore;
use PaxofiCloud\Application\Email\EmailAddress;
use PaxofiCloud\Application\Email\EmailTemplates;
use PaxofiCloud\Application\Staff\StaffAuthorizer;
use PaxofiCloud\Application\Staff\StaffPermissionDenied;
use PaxofiCloud\Domain\Staff\Permission;
use PaxofiCloud\Domain\Staff\StaffRole;

/**
 * Inviting staff (threat model D-11, §6.17). The invitee gets an email link
 * to the admin origin; the token is never returned or printed. A staff
 * manager invites with one starting role (never Super Admin: that grant
 * needs a second approver, SEC-017). The operator bootstrap invites the first
 * Security Admin and refuses once any active staff manager exists.
 */
final readonly class StaffInvitations
{
    public const int TTL_SECONDS = 86_400;

    public function __construct(
        private StaffPasskeyStore $store,
        private StaffDirectory $directory,
        private StaffAuthorizer $authorizer,
        private TransactionManager $transactions,
        private EmailOutbox $outbox,
        private Clock $clock,
        private AuditTrail $audit,
    ) {
    }

    /**
     * @return string the new staff ID
     * @throws StaffPermissionDenied|StaffInvitationRefused
     */
    public function invite(string $actorId, string $email, string $fullName, StaffRole $role, ClientContext $client): string
    {
        $this->authorizer->authorize($actorId, Permission::StaffManage, $client);
        if ($role->needsSecondApprover()) {
            $this->refuse(StaffInvitationRefused::ROLE_NEEDS_APPROVAL, ['actor' => $actorId, 'role' => $role->value], $client);
        }
        $staffId = $this->create($email, $fullName, $role, $actorId, ['actor' => $actorId, 'role' => $role->value], $client, false);
        $this->audit->record(AuditEventType::STAFF_INVITED, AuditEvent::SUCCESS, $client, details: ['actor' => $actorId, 'subject' => $staffId, 'role' => $role->value]);

        return $staffId;
    }

    /**
     * Operator-only (host CLI): invites the first Security Admin.
     *
     * @throws StaffInvitationRefused
     */
    public function bootstrap(string $email, string $fullName, ClientContext $client): string
    {
        $reissued = false;
        $staffId = $this->create($email, $fullName, StaffRole::SecurityAdmin, null, ['role' => StaffRole::SecurityAdmin->value], $client, true, $reissued);
        $details = ['subject' => $staffId, 'role' => StaffRole::SecurityAdmin->value];
        if ($reissued) {
            $details['via'] = 'reissue';
        }
        $this->audit->record(AuditEventType::STAFF_BOOTSTRAP_INVITED, AuditEvent::SUCCESS, $client, details: $details);

        return $staffId;
    }

    /**
     * @param array<string, string> $details
     * @throws StaffInvitationRefused
     */
    private function create(string $email, string $fullName, StaffRole $role, ?string $actorId, array $details, ClientContext $client, bool $bootstrap, bool &$reissued = false): string
    {
        try {
            $address = new EmailAddress($email);
        } catch (\InvalidArgumentException) {
            $this->refuse(StaffInvitationRefused::INVALID_INPUT, $details, $client);
        }
        $name = trim($fullName);
        if ($name === '' || mb_strlen($name) > 100 || preg_match('/[\p{C}<>]/u', $name) === 1) {
            $this->refuse(StaffInvitationRefused::INVALID_INPUT, $details, $client);
        }

        $staffId = bin2hex(random_bytes(16));
        $token = PasskeyTokens::newToken();
        try {
            $this->transactions->transaction(function () use ($address, $name, $role, $actorId, $bootstrap, &$staffId, $token, &$reissued): void {
                if ($bootstrap && $this->directory->countActiveHoldersForUpdate(self::managerRoles()) > 0) {
                    throw new StaffInvitationRefused(StaffInvitationRefused::MANAGERS_EXIST);
                }
                if ($this->store->staffExistsWithEmail($address->hash())) {
                    // Bootstrap only, and only while nobody can manage staff: the first admin's invitation
                    // email was lost (e.g. the mail relay failed), so issue a fresh link to the same
                    // address and cancel the old ones (identity threat model §6.19). Anyone else is refused.
                    $invited = $bootstrap ? $this->store->invitedMemberWithEmailForUpdate($address->hash()) : null;
                    if ($invited === null) {
                        throw new StaffInvitationRefused(StaffInvitationRefused::ALREADY_STAFF);
                    }
                    $now = $this->clock->now();
                    $this->store->cancelOpenInvitations($invited['staff_id']);
                    $this->store->addInvitation(hash('sha256', $token), $invited['staff_id'], null, $now, $now->modify('+' . self::TTL_SECONDS . ' seconds'));
                    // Always to the address on the account, never the one typed this time.
                    $this->outbox->enqueue(new EmailAddress($invited['email']), EmailTemplates::STAFF_INVITATION, ['name' => $name, 'token' => $token]);
                    $staffId = $invited['staff_id'];
                    $reissued = true;

                    return;
                }
                $now = $this->clock->now();
                $this->store->createInvitedMember($staffId, $address->value, $address->hash(), $name, random_bytes(32), $now);
                $this->store->addInvitation(hash('sha256', $token), $staffId, $actorId, $now, $now->modify('+' . self::TTL_SECONDS . ' seconds'));
                // The starting role is granted now but gives nothing until the member is active (enrolled).
                $this->directory->addGrant(bin2hex(random_bytes(16)), $staffId, $role, 'active', $actorId, $now);
                $this->outbox->enqueue($address, EmailTemplates::STAFF_INVITATION, ['name' => $name, 'token' => $token]);
            });
        } catch (StaffInvitationRefused $e) {
            $this->refuse($e->reason, $details, $client);
        }

        return $staffId;
    }

    /**
     * @param array<string, string> $details
     * @throws StaffInvitationRefused
     */
    private function refuse(string $reason, array $details, ClientContext $client): never
    {
        $this->audit->record(AuditEventType::STAFF_INVITATION_REFUSED, AuditEvent::FAILURE, $client, details: $details + ['reason' => $reason]);

        throw new StaffInvitationRefused($reason);
    }

    /** @return list<StaffRole> */
    private static function managerRoles(): array
    {
        $roles = [];
        foreach (StaffRole::cases() as $role) {
            if (in_array(Permission::StaffManage, $role->permissions(), true)) {
                $roles[] = $role;
            }
        }

        return $roles;
    }
}
