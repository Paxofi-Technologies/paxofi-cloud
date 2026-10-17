<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Identity;

use Paxofi\Core\Contracts\Connection;
use Paxofi\Core\Contracts\Repository;
use PaxofiCloud\Application\Contracts\IdentityStore;
use PaxofiCloud\Infrastructure\Persistence\Row;

/** MySQL implementation of registration storage (migration 0003). */
final readonly class MysqlIdentityStore implements IdentityStore
{
    private const string TIME = 'Y-m-d H:i:s.u';

    public function __construct(private Connection $connection, private Repository $repository)
    {
    }

    public function findByEmailLookupForUpdate(string $emailLookup): ?array
    {
        $rows = $this->repository->fetchAll(
            'SELECT id, status, full_name FROM identities WHERE email_lookup = :lookup FOR UPDATE',
            ['lookup' => $emailLookup],
        );
        if ($rows === []) {
            return null;
        }

        return ['id' => Row::string($rows[0], 'id'), 'status' => Row::string($rows[0], 'status'), 'full_name' => Row::string($rows[0], 'full_name')];
    }

    public function createPending(
        string $identityId,
        string $accountId,
        string $email,
        string $emailLookup,
        string $fullName,
        string $phoneE164,
        #[\SensitiveParameter] string $passwordHash,
        string $termsVersion,
        \DateTimeImmutable $now,
    ): void {
        $this->connection->execute(
            'INSERT INTO identities (id, email, email_lookup, full_name, phone_e164, password_hash, status, terms_version, terms_accepted_at)
             VALUES (:id, :email, :lookup, :name, :phone, :hash, \'pending_verification\', :terms, :now)',
            ['id' => $identityId, 'email' => $email, 'lookup' => $emailLookup, 'name' => $fullName, 'phone' => $phoneE164, 'hash' => $passwordHash, 'terms' => $termsVersion, 'now' => self::time($now)],
        );
        $this->connection->execute(
            'INSERT INTO accounts (id, type, name) VALUES (:id, \'personal\', :name)',
            ['id' => $accountId, 'name' => $fullName],
        );
        $this->connection->execute(
            'INSERT INTO account_members (account_id, identity_id, role) VALUES (:account, :identity, \'owner\')',
            ['account' => $accountId, 'identity' => $identityId],
        );
    }

    public function overwritePending(
        string $identityId,
        string $fullName,
        string $phoneE164,
        #[\SensitiveParameter] string $passwordHash,
        string $termsVersion,
        \DateTimeImmutable $now,
    ): void {
        $this->connection->execute(
            'UPDATE identities SET full_name = :name, phone_e164 = :phone, password_hash = :hash, terms_version = :terms, terms_accepted_at = :now
             WHERE id = :id AND status = \'pending_verification\'',
            ['id' => $identityId, 'name' => $fullName, 'phone' => $phoneE164, 'hash' => $passwordHash, 'terms' => $termsVersion, 'now' => self::time($now)],
        );
    }

    public function replaceVerificationToken(string $identityId, string $tokenHash, \DateTimeImmutable $expiresAt): void
    {
        $this->connection->execute(
            'DELETE FROM email_verification_tokens WHERE identity_id = :id AND used_at IS NULL',
            ['id' => $identityId],
        );
        $this->connection->execute(
            'INSERT INTO email_verification_tokens (token_hash, identity_id, expires_at) VALUES (:hash, :id, :expires)',
            ['hash' => $tokenHash, 'id' => $identityId, 'expires' => self::time($expiresAt)],
        );
    }

    public function consumeVerificationToken(string $tokenHash, \DateTimeImmutable $now): ?string
    {
        $rows = $this->repository->fetchAll(
            'SELECT identity_id FROM email_verification_tokens
             WHERE token_hash = :hash AND used_at IS NULL AND expires_at > :now FOR UPDATE',
            ['hash' => $tokenHash, 'now' => self::time($now)],
        );
        if ($rows === []) {
            return null;
        }
        $updated = $this->connection->execute(
            'UPDATE email_verification_tokens SET used_at = :now WHERE token_hash = :hash AND used_at IS NULL',
            ['hash' => $tokenHash, 'now' => self::time($now)],
        );

        return $updated === 1 ? Row::string($rows[0], 'identity_id') : null;
    }

    public function activate(string $identityId, \DateTimeImmutable $now): bool
    {
        return $this->connection->execute(
            'UPDATE identities SET status = \'active\', verified_at = :now WHERE id = :id AND status = \'pending_verification\'',
            ['id' => $identityId, 'now' => self::time($now)],
        ) === 1;
    }

    private static function time(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format(self::TIME);
    }
}
