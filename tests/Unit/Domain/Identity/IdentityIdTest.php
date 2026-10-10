<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Domain\Identity;

use PaxofiCloud\Domain\Identity\IdentityId;
use PHPUnit\Framework\TestCase;

final class IdentityIdTest extends TestCase
{
    public function testAcceptsNonEmptyValue(): void
    {
        $id = new IdentityId('identity-1');

        self::assertSame('identity-1', $id->value);
        self::assertSame('identity-1', (string) $id);
    }

    public function testRejectsEmptyValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new IdentityId('');
    }
}
