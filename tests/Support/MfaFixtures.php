<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Support;

use PaxofiCloud\Application\Identity\Mfa\MfaCrypto;
use PaxofiCloud\Application\Identity\Mfa\RecoveryCodes;
use PaxofiCloud\Infrastructure\Secrets\KeyRing;
use PaxofiCloud\Infrastructure\Secrets\SecretCipher;

/** Fixed test keys (never used outside tests). */
final class MfaFixtures
{
    public static function crypto(): MfaCrypto
    {
        return new MfaCrypto(
            new SecretCipher(new KeyRing(['t1' => str_repeat("\x01", 32)], 't1')),
            new RecoveryCodes(str_repeat("\x02", 32)),
        );
    }
}
