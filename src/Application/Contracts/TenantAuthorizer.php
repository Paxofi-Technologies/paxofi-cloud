<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Contracts;

use PaxofiCloud\Domain\Tenant\TenantId;

interface TenantAuthorizer
{
    public function assertCanAccess(TenantId $tenantId, RequestContext $context): void;
}
