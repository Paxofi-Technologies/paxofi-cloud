<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Application\Identity;

use PaxofiCloud\Application\Contracts\RequestContext;
use PaxofiCloud\Application\Contracts\TenantMembershipReader;
use PaxofiCloud\Application\Identity\AuthoritativeTenantAuthorizer;
use PaxofiCloud\Application\Identity\UnauthorizedTenantAccess;
use PaxofiCloud\Domain\Identity\IdentityId;
use PaxofiCloud\Domain\Tenant\TenantId;

final class AuthoritativeTenantAuthorizerTest
{
    public static function run(): void
    {
        $identity = new IdentityId('identity-1');
        $tenant = new TenantId('tenant-1');
        $otherTenant = new TenantId('tenant-2');
        $context = new RequestContext($identity, $tenant, 'corr-1');

        $reader = new class implements TenantMembershipReader {
            public function isMember(IdentityId $identityId, TenantId $tenantId): bool
            {
                return $identityId->value === 'identity-1' && $tenantId->value === 'tenant-1';
            }
        };

        $authorizer = new AuthoritativeTenantAuthorizer($reader);
        $authorizer->assertCanAccess($tenant, $context);

        try {
            $authorizer->assertCanAccess($otherTenant, $context);
        } catch (UnauthorizedTenantAccess) {
            return;
        }

        throw new \RuntimeException('Cross-tenant access must be rejected.');
    }
}
