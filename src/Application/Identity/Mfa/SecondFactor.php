<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Identity\Mfa;

use PaxofiCloud\Application\Contracts\Clock;
use PaxofiCloud\Application\Contracts\MfaStore;

/** Checks a TOTP code (once per step, T-13) or spends a recovery code (once, D-5). */
final readonly class SecondFactor
{
    public function __construct(private MfaStore $mfa, private MfaCrypto $crypto, private Clock $clock)
    {
    }

    public function verifyCode(string $identityId, #[\SensitiveParameter] string $code): bool
    {
        $totp = $this->mfa->totp($identityId);
        if ($totp === null || !$totp['confirmed']) {
            return false;
        }
        $secret = $this->crypto->secrets->decrypt($totp['secret_envelope'], MfaCrypto::associatedData($identityId));
        $step = Totp::match($secret, $code, $this->clock->now(), $totp['last_used_step']);

        // The store re-checks the step atomically, so two requests racing with one code cannot both pass.
        return $step !== null && $this->mfa->recordTotpStep($identityId, $step);
    }

    public function verifyRecoveryCode(string $identityId, #[\SensitiveParameter] string $code): bool
    {
        $hash = $this->crypto->recoveryCodes->hash($code);

        return $hash !== null && $this->mfa->useRecoveryCode($identityId, $hash, $this->clock->now());
    }
}
