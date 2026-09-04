<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Identity;

use PaxofiCloud\Application\Contracts\RequestContext;
use PaxofiCloud\Application\Contracts\TenantAuthorizer;
use PaxofiCloud\Application\Contracts\TenantMembershipReader;
use PaxofiCloud\Domain\Tenant\TenantId;

/**
 * Enforces tenant isolation at the application boundary.
 *
 * The requested tenant must match the authenticated request context and the
 * identity must have authoritative membership. Client-supplied tenant claims
 * are never sufficient on their own.
 */
final readonly class AuthoritativeTenantAuthorizer implements TenantAuthorizer
{
    public function __construct(private TenantMembershipReader $membershipReader)
    {
    }

    public function assertCanAccess(TenantId $tenantId, RequestContext $context): void
    {
        if ($tenantId->value !== $context->tenantId->value) {
            throw UnauthorizedTenantAccess::for($context->identityId, $tenantId);
        }

        if (!$this->membershipReader->isMember($context->identityId, $tenantId)) {
            throw UnauthorizedTenantAccess::for($context->identityId, $tenantId);
        }
    }
}
