<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Contracts;

use PaxofiCloud\Domain\Identity\IdentityId;
use PaxofiCloud\Domain\Tenant\TenantId;

/**
 * Reads the authoritative tenant membership boundary.
 *
 * Implementations may be backed by a database or another governed identity
 * source. The application layer must not infer membership from client input.
 */
interface TenantMembershipReader
{
    public function isMember(IdentityId $identityId, TenantId $tenantId): bool;
}
