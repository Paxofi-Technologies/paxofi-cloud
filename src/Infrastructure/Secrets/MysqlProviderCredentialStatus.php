<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Secrets;

use Paxofi\Core\Contracts\Repository;
use PaxofiCloud\Application\Contracts\ProviderCredentialStatus;
use PaxofiCloud\Infrastructure\Persistence\Row;

/**
 * Reads credential metadata only; the envelope column is never selected (PC-2).
 * Covers provider and payment gateway credentials (PY-3) for the admin overview.
 */
final readonly class MysqlProviderCredentialStatus implements ProviderCredentialStatus
{
    public function __construct(private Repository $repository)
    {
    }

    public function configured(): array
    {
        $result = [];
        foreach ($this->repository->fetchAll('SELECT id, updated_at, review_due_on FROM provider_credentials UNION ALL SELECT id, updated_at, review_due_on FROM payment_gateway_credentials') as $row) {
            $result[Row::string($row, 'id')] = [
                'updated_at' => new \DateTimeImmutable(Row::string($row, 'updated_at'), new \DateTimeZone('UTC')),
                'review_due_on' => new \DateTimeImmutable(Row::string($row, 'review_due_on'), new \DateTimeZone('UTC')),
            ];
        }

        return $result;
    }
}
