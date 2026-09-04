<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Identity;

use PaxofiCloud\Domain\Identity\IdentityId;
use PaxofiCloud\Domain\Tenant\TenantId;

final class UnauthorizedTenantAccess extends \RuntimeException
{
    public static function for(IdentityId $identityId, TenantId $tenantId): self
    {
        return new self(sprintf(
            'Identity "%s" is not authorized for tenant "%s".',
            $identityId->value,
            $tenantId->value,
        ));
    }
}
