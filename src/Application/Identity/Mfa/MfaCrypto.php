<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Identity\Mfa;

use PaxofiCloud\Application\Contracts\SecretEncryption;

/** The two keys MFA needs (§6.13); absent when the server has none configured. */
final readonly class MfaCrypto
{
    public function __construct(public SecretEncryption $secrets, public RecoveryCodes $recoveryCodes)
    {
    }

    public static function associatedData(string $identityId): string
    {
        return 'totp:' . $identityId;
    }
}
