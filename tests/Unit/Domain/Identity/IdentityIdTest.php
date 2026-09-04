<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Domain\Identity;

use PaxofiCloud\Domain\Identity\IdentityId;

final class IdentityIdTest
{
    public static function acceptsNonEmptyValue(): void
    {
        $id = new IdentityId('identity-1');

        if ((string) $id !== 'identity-1') {
            throw new \RuntimeException('Identity ID value was not preserved.');
        }
    }
}
