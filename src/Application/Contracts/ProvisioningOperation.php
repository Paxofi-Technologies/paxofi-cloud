<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Contracts;

use PaxofiCloud\Domain\Tenant\TenantId;

interface ProvisioningOperation
{
    /** @param array<string, scalar|null> $parameters */
    public function enqueue(TenantId $tenantId, string $operationType, array $parameters, string $idempotencyKey): string;
}
