<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Domain\Tenant;

use PaxofiCloud\Domain\Tenant\TenantId;
use PHPUnit\Framework\TestCase;

final class TenantIdTest extends TestCase
{
    public function testAcceptsNonEmptyValue(): void
    {
        $id = new TenantId('tenant-1');

        self::assertSame('tenant-1', $id->value);
        self::assertSame('tenant-1', (string) $id);
    }

    public function testRejectsEmptyValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TenantId('');
    }
}
