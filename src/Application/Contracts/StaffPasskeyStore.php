<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Contracts;

use PaxofiCloud\Application\Staff\Passkeys\StoredPasskey;
use PaxofiCloud\Application\Staff\Passkeys\VerifiedPasskey;

/** Staff invitations and passkeys (migration 0008; threat model §6.17, §6.20). */
interface StaffPasskeyStore
{
    public function staffExistsWithEmail(string $emailLookup): bool;

    /**
     * The member with this address if they were invited but never finished setting up; locked for the transaction.
     *
     * @return array{staff_id: string, email: string}|null the stored address, not the one asked with
     */
    public function invitedMemberWithEmailForUpdate(string $emailLookup): ?array;

    /** Cancels every unused invitation of this member, so only the newest link works. */
    public function cancelOpenInvitations(string $staffId): void;

    public function createInvitedMember(string $staffId, string $email, string $emailLookup, string $fullName, string $userHandle, \DateTimeImmutable $now): void;

    public function addInvitation(string $tokenHash, string $staffId, ?string $invitedBy, \DateTimeImmutable $now, \DateTimeImmutable $expiresAt): void;

    /**
     * An unused, unexpired invitation for a member who is still invited,
     * locked for the rest of the transaction.
     *
     * @return array{staff_id: string, email: string, full_name: string, user_handle: string}|null
     */
    public function openInvitationForUpdate(string $tokenHash, \DateTimeImmutable $now): ?array;

    /**
     * An active member's details for a passkey registration (§6.20).
     *
     * @return array{email: string, full_name: string, user_handle: string}|null
     */
    public function activeMemberForPasskeys(string $staffId): ?array;

    /** @return list<string> raw credential IDs of the member's passkeys, disabled ones included */
    public function credentialIdsOf(string $staffId): array;

    public function addPasskey(string $passkeyId, string $staffId, VerifiedPasskey $passkey, string $label, \DateTimeImmutable $now): void;

    /** Marks the invitation used and the member active, in the caller's transaction. */
    public function completeEnrolment(string $tokenHash, string $staffId, \DateTimeImmutable $now): void;

    /** An enabled passkey of an active member. */
    public function activePasskeyByCredential(string $credentialLookup): ?StoredPasskey;

    public function recordUse(string $passkeyId, int $signCount, bool $backupState, \DateTimeImmutable $now): void;

    public function disablePasskey(string $passkeyId, string $reason, \DateTimeImmutable $now): void;
}
