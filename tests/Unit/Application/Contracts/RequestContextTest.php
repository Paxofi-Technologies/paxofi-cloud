<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Application\Contracts;

use PaxofiCloud\Application\Contracts\RequestContext;
use PaxofiCloud\Domain\Identity\IdentityId;
use PaxofiCloud\Domain\Tenant\TenantId;

final class RequestContextTest
{
    public static function preservesSecurityContext(): void
    {
        $context = new RequestContext(
            new IdentityId('identity-1'),
            new TenantId('tenant-1'),
            'correlation-1',
        );

        if ($context->tenantId->value !== 'tenant-1' || $context->identityId->value !== 'identity-1') {
            throw new \RuntimeException('Request security context was not preserved.');
        }
    }
}
