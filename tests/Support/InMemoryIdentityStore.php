<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Support;

use PaxofiCloud\Application\Contracts\IdentityStore;

/** Mirrors MysqlIdentityStore's rules in memory (integration tests prove the SQL). */
final class InMemoryIdentityStore implements IdentityStore
{
    /** @var array<string, array{id: string, email: string, lookup: string, full_name: string, phone: string, password_hash: string, status: string, terms: string, verified_at: ?\DateTimeImmutable, account_id: string}> */
    public array $identities = [];
    /** @var array<string, array{identity_id: string, expires_at: \DateTimeImmutable, used_at: ?\DateTimeImmutable}> */
    public array $tokens = [];
    public int $writes = 0;

    public function findByEmailLookupForUpdate(string $emailLookup): ?array
    {
        foreach ($this->identities as $identity) {
            if ($identity['lookup'] === $emailLookup) {
                return ['id' => $identity['id'], 'status' => $identity['status'], 'full_name' => $identity['full_name']];
            }
        }

        return null;
    }

    public function createPending(string $identityId, string $accountId, string $email, string $emailLookup, string $fullName, string $phoneE164, string $passwordHash, string $termsVersion, \DateTimeImmutable $now): void
    {
        $this->writes++;
        $this->identities[$identityId] = ['id' => $identityId, 'email' => $email, 'lookup' => $emailLookup, 'full_name' => $fullName, 'phone' => $phoneE164, 'password_hash' => $passwordHash, 'status' => 'pending_verification', 'terms' => $termsVersion, 'verified_at' => null, 'account_id' => $accountId];
    }

    public function overwritePending(string $identityId, string $fullName, string $phoneE164, string $passwordHash, string $termsVersion, \DateTimeImmutable $now): void
    {
        $this->writes++;
        if ($this->identities[$identityId]['status'] === 'pending_verification') {
            $this->identities[$identityId] = ['full_name' => $fullName, 'phone' => $phoneE164, 'password_hash' => $passwordHash, 'terms' => $termsVersion] + $this->identities[$identityId];
        }
    }

    public function replaceVerificationToken(string $identityId, string $tokenHash, \DateTimeImmutable $expiresAt): void
    {
        $this->writes++;
        foreach ($this->tokens as $hash => $token) {
            if ($token['identity_id'] === $identityId && $token['used_at'] === null) {
                unset($this->tokens[$hash]);
            }
        }
        $this->tokens[$tokenHash] = ['identity_id' => $identityId, 'expires_at' => $expiresAt, 'used_at' => null];
    }

    public function consumeVerificationToken(string $tokenHash, \DateTimeImmutable $now): ?string
    {
        $token = $this->tokens[$tokenHash] ?? null;
        if ($token === null || $token['used_at'] !== null || $token['expires_at'] <= $now) {
            return null;
        }
        $this->tokens[$tokenHash]['used_at'] = $now;

        return $token['identity_id'];
    }

    public function activate(string $identityId, \DateTimeImmutable $now): bool
    {
        if (($this->identities[$identityId]['status'] ?? null) !== 'pending_verification') {
            return false;
        }
        $this->identities[$identityId]['status'] = 'active';
        $this->identities[$identityId]['verified_at'] = $now;

        return true;
    }
}
