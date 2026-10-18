<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Contracts;

/** Reads and updates what sign-in needs (threat model §6.11). */
interface SignInStore
{
    /** @return array{id: string, status: string, password_hash: string, account_id: string}|null */
    public function findForSignIn(string $emailLookup): ?array;

    /** D-3: stores a hash with the current Argon2id parameters after a successful sign-in. */
    public function replacePasswordHash(string $identityId, string $passwordHash): void;

    /** @return array{full_name: string, email: string, status: string}|null */
    public function findProfile(string $identityId): ?array;

    /** For re-checking the password before MFA changes (T-17). */
    public function passwordHash(string $identityId): ?string;
}
