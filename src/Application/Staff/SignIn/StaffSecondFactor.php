<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Staff\SignIn;

use PaxofiCloud\Application\Contracts\Clock;
use PaxofiCloud\Application\Contracts\StaffCredentialStore;
use PaxofiCloud\Application\Identity\Mfa\MfaCrypto;
use PaxofiCloud\Application\Identity\Mfa\Totp;

/**
 * A staff member's authenticator code (each time step once, T-13) or one of
 * their recovery codes (§6.20). The secret is bound to the staff ID, so a
 * customer's envelope can never decrypt as a staff secret.
 */
final readonly class StaffSecondFactor
{
    public function __construct(private StaffCredentialStore $store, private MfaCrypto $crypto, private Clock $clock)
    {
    }

    public static function associatedData(string $staffId): string
    {
        return 'staff-totp:' . $staffId;
    }

    public function verifyCode(string $staffId, #[\SensitiveParameter] string $code): bool
    {
        $totp = $this->store->totp($staffId);
        if ($totp === null || !$totp['confirmed']) {
            return false;
        }
        $secret = $this->crypto->secrets->decrypt($totp['secret_envelope'], self::associatedData($staffId));
        $step = Totp::match($secret, $code, $this->clock->now(), $totp['last_used_step']);

        // The store re-checks the step atomically, so two requests racing with one code cannot both pass.
        return $step !== null && $this->store->recordTotpStep($staffId, $step);
    }

    public function verifyRecoveryCode(string $staffId, #[\SensitiveParameter] string $code): bool
    {
        $hash = $this->crypto->recoveryCodes->hash($code);

        return $hash !== null && $this->store->useRecoveryCode($staffId, $hash, $this->clock->now());
    }
}
