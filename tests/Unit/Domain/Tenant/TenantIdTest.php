<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Domain\Tenant;

use PaxofiCloud\Domain\Tenant\TenantId;

final class TenantIdTest
{
    public static function acceptsNonEmptyValue(): void
    {
        $id = new TenantId('tenant-1');

        if ((string) $id !== 'tenant-1') {
            throw new \RuntimeException('Tenant ID value was not preserved.');
        }
    }
}
