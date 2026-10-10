<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Application\Identity;

use PaxofiCloud\Application\Contracts\RequestContext;
use PaxofiCloud\Application\Contracts\TenantMembershipReader;
use PaxofiCloud\Application\Identity\AuthoritativeTenantAuthorizer;
use PaxofiCloud\Application\Identity\UnauthorizedTenantAccess;
use PaxofiCloud\Domain\Identity\IdentityId;
use PaxofiCloud\Domain\Tenant\TenantId;
use PHPUnit\Framework\TestCase;

final class AuthoritativeTenantAuthorizerTest extends TestCase
{
    private AuthoritativeTenantAuthorizer $authorizer;

    protected function setUp(): void
    {
        $reader = new class implements TenantMembershipReader {
            public function isMember(IdentityId $identityId, TenantId $tenantId): bool
            {
                return $identityId->value === 'identity-1'
                    && in_array($tenantId->value, ['tenant-1', 'tenant-2'], true);
            }
        };

        $this->authorizer = new AuthoritativeTenantAuthorizer($reader);
    }

    public function testAllowsMemberAccessingOwnContextTenant(): void
    {
        $tenant = new TenantId('tenant-1');

        $this->authorizer->assertCanAccess($tenant, $this->context('identity-1', 'tenant-1'));

        $this->addToAssertionCount(1);
    }

    public function testRejectsTenantThatDiffersFromRequestContextEvenWhenMember(): void
    {
        $this->expectException(UnauthorizedTenantAccess::class);

        $this->authorizer->assertCanAccess(new TenantId('tenant-2'), $this->context('identity-1', 'tenant-1'));
    }

    public function testRejectsIdentityWithoutAuthoritativeMembership(): void
    {
        $this->expectException(UnauthorizedTenantAccess::class);

        $this->authorizer->assertCanAccess(new TenantId('tenant-1'), $this->context('identity-2', 'tenant-1'));
    }

    private function context(string $identity, string $tenant): RequestContext
    {
        return new RequestContext(new IdentityId($identity), new TenantId($tenant), 'corr-1');
    }
}
