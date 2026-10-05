<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Application\Contracts;

use PaxofiCloud\Application\Contracts\RequestContext;
use PaxofiCloud\Domain\Identity\IdentityId;
use PaxofiCloud\Domain\Tenant\TenantId;
use PHPUnit\Framework\TestCase;

final class RequestContextTest extends TestCase
{
    public function testPreservesSecurityContext(): void
    {
        $context = new RequestContext(
            new IdentityId('identity-1'),
            new TenantId('tenant-1'),
            'correlation-1',
        );

        self::assertSame('identity-1', $context->identityId->value);
        self::assertSame('tenant-1', $context->tenantId->value);
        self::assertSame('correlation-1', $context->correlationId);
    }

    public function testRejectsEmptyCorrelationId(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RequestContext(new IdentityId('identity-1'), new TenantId('tenant-1'), '');
    }
}
