<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Support;

use PaxofiCloud\Application\Contracts\StaffPasskeyStore;
use PaxofiCloud\Application\Staff\Passkeys\StoredPasskey;
use PaxofiCloud\Application\Staff\Passkeys\VerifiedPasskey;

/** Staff invitations and passkeys in memory; member status lives in the shared directory. */
final class InMemoryStaffPasskeyStore implements StaffPasskeyStore
{
    /** @var array<string, array{email: string, lookup: string, name: string, handle: string}> */
    public array $profiles = [];

    /** @var array<string, array{staff_id: string, invited_by: ?string, expires_at: \DateTimeImmutable, used: bool}> */
    public array $invitations = [];

    /** @var array<string, array{staff_id: string, passkey: VerifiedPasskey, label: string, sign_count: int, disabled: ?string}> */
    public array $passkeys = [];

    public function __construct(private InMemoryStaffDirectory $directory)
    {
    }

    public function staffExistsWithEmail(string $emailLookup): bool
    {
        foreach ($this->profiles as $profile) {
            if ($profile['lookup'] === $emailLookup) {
                return true;
            }
        }

        return false;
    }

    public function createInvitedMember(string $staffId, string $email, string $emailLookup, string $fullName, string $userHandle, \DateTimeImmutable $now): void
    {
        $this->profiles[$staffId] = ['email' => $email, 'lookup' => $emailLookup, 'name' => $fullName, 'handle' => $userHandle];
        $this->directory->members[$staffId] = 'invited';
    }

    public function addInvitation(string $tokenHash, string $staffId, ?string $invitedBy, \DateTimeImmutable $now, \DateTimeImmutable $expiresAt): void
    {
        $this->invitations[$tokenHash] = ['staff_id' => $staffId, 'invited_by' => $invitedBy, 'expires_at' => $expiresAt, 'used' => false];
    }

    public function openInvitationForUpdate(string $tokenHash, \DateTimeImmutable $now): ?array
    {
        $invitation = $this->invitations[$tokenHash] ?? null;
        if ($invitation === null || $invitation['used'] || $invitation['expires_at'] <= $now || ($this->directory->members[$invitation['staff_id']] ?? null) !== 'invited') {
            return null;
        }
        $profile = $this->profiles[$invitation['staff_id']];

        return ['staff_id' => $invitation['staff_id'], 'email' => $profile['email'], 'full_name' => $profile['name'], 'user_handle' => $profile['handle']];
    }

    public function credentialIdsOf(string $staffId): array
    {
        $ids = [];
        foreach ($this->passkeys as $row) {
            if ($row['staff_id'] === $staffId) {
                $ids[] = $row['passkey']->credentialId;
            }
        }

        return $ids;
    }

    public function addPasskey(string $passkeyId, string $staffId, VerifiedPasskey $passkey, string $label, \DateTimeImmutable $now): void
    {
        foreach ($this->passkeys as $row) {
            if ($row['passkey']->credentialId === $passkey->credentialId) {
                throw new \RuntimeException('duplicate credential');
            }
        }
        $this->passkeys[$passkeyId] = ['staff_id' => $staffId, 'passkey' => $passkey, 'label' => $label, 'sign_count' => $passkey->signCount, 'disabled' => null];
    }

    public function completeEnrolment(string $tokenHash, string $staffId, \DateTimeImmutable $now): void
    {
        $this->invitations[$tokenHash]['used'] = true;
        $this->directory->members[$staffId] = 'active';
    }

    public function activePasskeyByCredential(string $credentialLookup): ?StoredPasskey
    {
        foreach ($this->passkeys as $id => $row) {
            $p = $row['passkey'];
            if (hash('sha256', $p->credentialId) === $credentialLookup && $row['disabled'] === null && ($this->directory->members[$row['staff_id']] ?? null) === 'active') {
                return new StoredPasskey((string) $id, $row['staff_id'], $p->credentialId, $p->publicKey, $p->algorithm, $row['sign_count'], $p->aaguid, implode(',', $p->transports), $p->backupEligible, $p->backupState, $this->profiles[$row['staff_id']]['handle']);
            }
        }

        return null;
    }

    public function recordUse(string $passkeyId, int $signCount, bool $backupState, \DateTimeImmutable $now): void
    {
        $this->passkeys[$passkeyId]['sign_count'] = $signCount;
    }

    public function disablePasskey(string $passkeyId, string $reason, \DateTimeImmutable $now): void
    {
        $this->passkeys[$passkeyId]['disabled'] = $reason;
    }
}
