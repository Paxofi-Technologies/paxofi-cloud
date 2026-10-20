<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Secrets;

use Paxofi\Core\Contracts\Connection;
use Paxofi\Core\Contracts\Repository;
use Paxofi\Core\Contracts\TransactionManager;
use PaxofiCloud\Application\Contracts\CredentialIntake;
use PaxofiCloud\Infrastructure\Persistence\Row;

/** Migration 0012. Rows hold sealed boxes only; replacing is delete + insert (no UPDATE grant, PC-5). */
final readonly class MysqlCredentialIntake implements CredentialIntake
{
    public function __construct(
        private Connection $connection,
        private Repository $repository,
        private TransactionManager $transactions,
    ) {
    }

    public function replace(string $credentialId, string $provider, string $sealed, string $submittedBy, \DateTimeImmutable $now): void
    {
        $this->transactions->transaction(function () use ($credentialId, $provider, $sealed, $submittedBy, $now): void {
            $this->connection->execute('DELETE FROM provider_credential_intake WHERE credential_id = :id', ['id' => $credentialId]);
            $this->connection->execute(
                'INSERT INTO provider_credential_intake (credential_id, provider, sealed, submitted_by, submitted_at)
                 VALUES (:id, :provider, :sealed, :submitted_by, :submitted_at)',
                [
                    'id' => $credentialId,
                    'provider' => $provider,
                    'sealed' => $sealed,
                    'submitted_by' => $submittedBy,
                    'submitted_at' => $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
                ],
            );
        });
    }

    public function pending(): array
    {
        $pending = [];
        foreach ($this->repository->fetchAll('SELECT credential_id, submitted_at FROM provider_credential_intake') as $row) {
            $pending[Row::string($row, 'credential_id')] = new \DateTimeImmutable(Row::string($row, 'submitted_at'), new \DateTimeZone('UTC'));
        }

        return $pending;
    }

    public function oldest(int $limit): array
    {
        $rows = $this->repository->fetchAll(
            'SELECT credential_id, provider, sealed, submitted_by FROM provider_credential_intake ORDER BY submitted_at, credential_id LIMIT 20',
        );

        return array_map(static fn (array $row): array => [
            'credential_id' => Row::string($row, 'credential_id'),
            'provider' => Row::string($row, 'provider'),
            'sealed' => Row::string($row, 'sealed'),
            'submitted_by' => Row::string($row, 'submitted_by'),
        ], array_slice($rows, 0, max(1, $limit)));
    }

    /** Removes the row only if it still holds this box, so a newer submission made meanwhile is kept. */
    public function remove(string $credentialId, string $sealed): void
    {
        $this->connection->execute(
            'DELETE FROM provider_credential_intake WHERE credential_id = :id AND sealed = :sealed',
            ['id' => $credentialId, 'sealed' => $sealed],
        );
    }
}
