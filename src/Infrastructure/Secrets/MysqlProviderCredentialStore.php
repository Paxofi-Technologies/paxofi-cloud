<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Secrets;

use Paxofi\Core\Contracts\Connection;
use Paxofi\Core\Contracts\Repository;
use PaxofiCloud\Infrastructure\Persistence\Row;
use PaxofiCloud\Integration\Provider\ProviderCredential;
use PaxofiCloud\Integration\Provider\ProviderCredentialSource;

/**
 * Encrypted provider credentials in MySQL (SRS SEC-004, threat model V-01/V-04).
 *
 * Only workers construct this store: the web tier never receives the KEK
 * (threat model D-5). Plaintext tokens exist only in memory, inside
 * ProviderCredential, for the duration of a provider call.
 */
final class MysqlProviderCredentialStore implements ProviderCredentialSource
{
    public function __construct(
        private readonly Connection $connection,
        private readonly Repository $repository,
        private readonly SecretCipher $cipher,
    ) {
    }

    /**
     * Creates or replaces the secret for a credential. A credential's provider,
     * environment and access level are fixed once created; changing them
     * means creating a new credential ID.
     */
    public function save(StoredCredential $credential, #[\SensitiveParameter] string $secret, \DateTimeImmutable $reviewDueOn): void
    {
        $existing = $this->metadata($credential->id);
        if ($existing !== null && $existing != $credential) {
            throw new \InvalidArgumentException(sprintf('Credential "%s" already exists with different provider, environment or access level.', $credential->id));
        }

        $envelope = $this->cipher->encrypt($secret, $credential->associatedData());
        $this->connection->execute(
            'INSERT INTO provider_credentials (id, provider, environment, access_level, envelope, key_id, review_due_on)
             VALUES (:id, :provider, :environment, :access_level, :envelope, :key_id, :review_due_on)
             ON DUPLICATE KEY UPDATE envelope = VALUES(envelope), key_id = VALUES(key_id), review_due_on = VALUES(review_due_on)',
            [
                'id' => $credential->id,
                'provider' => $credential->provider,
                'environment' => $credential->environment,
                'access_level' => $credential->accessLevel,
                'envelope' => $envelope,
                'key_id' => SecretCipher::keyIdOf($envelope),
                'review_due_on' => $reviewDueOn->format('Y-m-d'),
            ],
        );
    }

    public function load(string $credentialId): ProviderCredential
    {
        $row = $this->row($credentialId) ?? throw new \OutOfBoundsException(sprintf('No provider credential "%s".', $credentialId));
        $metadata = self::toMetadata($row);

        return new ProviderCredential($metadata->id, $this->cipher->decrypt(Row::string($row, 'envelope'), $metadata->associatedData()));
    }

    /**
     * Re-encrypts every credential still on an older key with the active key
     * (key rotation step 2). Each row is updated only if it has not changed
     * since it was read, so concurrent writers are never overwritten.
     *
     * @return int number of credentials re-encrypted
     */
    public function reencryptAll(): int
    {
        $count = 0;
        foreach ($this->repository->fetchAll('SELECT id, provider, environment, access_level, envelope FROM provider_credentials ORDER BY id') as $row) {
            $envelope = Row::string($row, 'envelope');
            if (!$this->cipher->needsReencryption($envelope)) {
                continue;
            }
            $aad = self::toMetadata($row)->associatedData();
            $fresh = $this->cipher->encrypt($this->cipher->decrypt($envelope, $aad), $aad);
            $count += $this->connection->execute(
                'UPDATE provider_credentials SET envelope = :fresh, key_id = :key_id WHERE id = :id AND envelope = :old',
                ['fresh' => $fresh, 'key_id' => SecretCipher::keyIdOf($fresh), 'id' => Row::string($row, 'id'), 'old' => $envelope],
            );
        }

        return $count;
    }

    /** @return list<CredentialSummary> metadata of every stored credential, ordered by ID */
    public function summaries(): array
    {
        $summaries = [];
        foreach ($this->repository->fetchAll('SELECT id, provider, environment, access_level, key_id, review_due_on FROM provider_credentials ORDER BY id') as $row) {
            $reviewDueOn = \DateTimeImmutable::createFromFormat('!Y-m-d', Row::string($row, 'review_due_on'));
            if ($reviewDueOn === false) {
                throw new \UnexpectedValueException(sprintf('Credential "%s" has an invalid review date.', Row::string($row, 'id')));
            }
            $summaries[] = new CredentialSummary(self::toMetadata($row), Row::string($row, 'key_id'), $reviewDueOn);
        }

        return $summaries;
    }

    public function needsReencryption(CredentialSummary $summary): bool
    {
        return $this->cipher->isOnRetiredKey($summary->keyId);
    }

    private function metadata(string $credentialId): ?StoredCredential
    {
        $row = $this->row($credentialId);

        return $row === null ? null : self::toMetadata($row);
    }

    /** @return array<string, mixed>|null */
    private function row(string $credentialId): ?array
    {
        $rows = $this->repository->fetchAll(
            'SELECT id, provider, environment, access_level, envelope FROM provider_credentials WHERE id = :id',
            ['id' => $credentialId],
        );

        return $rows[0] ?? null;
    }

    /** @param array<string, mixed> $row */
    private static function toMetadata(array $row): StoredCredential
    {
        return new StoredCredential(Row::string($row, 'id'), Row::string($row, 'provider'), Row::string($row, 'environment'), Row::string($row, 'access_level'));
    }
}
