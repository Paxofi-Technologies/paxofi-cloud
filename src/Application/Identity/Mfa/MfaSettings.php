<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Identity\Mfa;

use Paxofi\Core\Contracts\PasswordHasher;
use PaxofiCloud\Application\Audit\AuditEvent;
use PaxofiCloud\Application\Audit\AuditEventType;
use PaxofiCloud\Application\Audit\AuditTrail;
use PaxofiCloud\Application\Audit\ClientContext;
use PaxofiCloud\Application\Contracts\Clock;
use PaxofiCloud\Application\Contracts\MfaStore;
use PaxofiCloud\Application\Contracts\RateLimiter;
use PaxofiCloud\Application\Contracts\SignInStore;

/**
 * Turning TOTP on and off for the signed-in customer (SRS IAM-007, IAM-013,
 * IAM-014; threat model D-4, D-5, T-13, T-15, T-17, §6.13). Every change that
 * weakens or replaces a factor needs the current password in the same request.
 */
final readonly class MfaSettings
{
    public const int PASSWORD_LIMIT = 5;
    public const int CODE_LIMIT = 5;
    public const int WINDOW_SECONDS = 900;

    public function __construct(
        private MfaStore $mfa,
        private SignInStore $identities,
        private PasswordHasher $hasher,
        private RateLimiter $limiter,
        private Clock $clock,
        private ?MfaCrypto $crypto,
        private AuditTrail $audit,
    ) {
    }

    /** @return array{totp_enabled: bool, recovery_codes_left: int} */
    public function status(string $identityId): array
    {
        $totp = $this->mfa->totp($identityId);
        $enabled = $totp !== null && $totp['confirmed'];

        return ['totp_enabled' => $enabled, 'recovery_codes_left' => $enabled ? $this->mfa->unusedRecoveryCodes($identityId) : 0];
    }

    public function isEnabled(string $identityId): bool
    {
        $totp = $this->mfa->totp($identityId);

        return $totp !== null && $totp['confirmed'];
    }

    /**
     * @param array<array-key, mixed> $data `{password}`
     * @throws InvalidMfaInput|MfaThrottled|MfaAlreadyEnabled|MfaUnavailable
     */
    public function setup(string $identityId, string $email, #[\SensitiveParameter] array $data, ClientContext $client): TotpSetup
    {
        $crypto = $this->crypto();
        $this->assertPassword($identityId, self::fields($data, ['password'])['password'], $client, 'setup');
        if ($this->isEnabled($identityId)) {
            throw new MfaAlreadyEnabled();
        }

        $secret = Totp::newSecret();
        $this->mfa->saveTotpSetup($identityId, $crypto->secrets->encrypt($secret, MfaCrypto::associatedData($identityId)));

        return new TotpSetup(Base32::encode($secret), Totp::uri($secret, $email));
    }

    /**
     * Confirms the pending secret with a code from the app and returns the
     * recovery codes, which are never shown again.
     *
     * @param array<array-key, mixed> $data `{code}`
     * @return list<string>
     * @throws InvalidMfaInput|MfaThrottled|MfaAlreadyEnabled|MfaNotEnabled|MfaUnavailable
     */
    public function confirm(string $identityId, #[\SensitiveParameter] array $data, ClientContext $client): array
    {
        $crypto = $this->crypto();
        $code = self::fields($data, ['code'])['code'];
        $totp = $this->mfa->totp($identityId) ?? throw new MfaNotEnabled();
        if ($totp['confirmed']) {
            throw new MfaAlreadyEnabled();
        }
        $this->throttle('mfa:confirm:' . $identityId, self::CODE_LIMIT);

        $secret = $crypto->secrets->decrypt($totp['secret_envelope'], MfaCrypto::associatedData($identityId));
        $now = $this->clock->now();
        $step = Totp::match($secret, $code, $now, null);
        if ($step === null) {
            $this->refused($client, $identityId, 'confirm', 'wrong_code');
            throw new InvalidMfaInput(['code' => 'incorrect']);
        }
        if (!$this->mfa->confirmTotp($identityId, $step, $now)) {
            throw new MfaNotEnabled();
        }
        $codes = $this->newRecoveryCodes($identityId, $crypto);
        $this->audit->record(AuditEventType::MFA_ENABLED, AuditEvent::SUCCESS, $client, $identityId, details: ['method' => 'totp']);

        return $codes;
    }

    /**
     * @param array<array-key, mixed> $data `{password}`
     * @return list<string>
     * @throws InvalidMfaInput|MfaThrottled|MfaNotEnabled|MfaUnavailable
     */
    public function regenerateRecoveryCodes(string $identityId, #[\SensitiveParameter] array $data, ClientContext $client): array
    {
        $crypto = $this->crypto();
        $this->assertPassword($identityId, self::fields($data, ['password'])['password'], $client, 'recovery_codes');
        if (!$this->isEnabled($identityId)) {
            throw new MfaNotEnabled();
        }
        $codes = $this->newRecoveryCodes($identityId, $crypto);
        $this->audit->record(AuditEventType::RECOVERY_CODES_REGENERATED, AuditEvent::SUCCESS, $client, $identityId);

        return $codes;
    }

    /**
     * Needs the password AND a current code or an unused recovery code, so a
     * stolen session alone cannot remove the second factor.
     *
     * @param array<array-key, mixed> $data `{password, code}` or `{password, recovery_code}`
     * @throws InvalidMfaInput|MfaThrottled|MfaNotEnabled|MfaUnavailable
     */
    public function disable(string $identityId, #[\SensitiveParameter] array $data, ClientContext $client): void
    {
        $crypto = $this->crypto();
        $hasCode = array_key_exists('code', $data);
        $fields = self::fields($data, ['password', $hasCode ? 'code' : 'recovery_code']);
        $this->assertPassword($identityId, $fields['password'], $client, 'disable');
        if (!$this->isEnabled($identityId)) {
            throw new MfaNotEnabled();
        }
        $this->throttle('mfa:disable:' . $identityId, self::CODE_LIMIT);

        $verifier = new SecondFactor($this->mfa, $crypto, $this->clock);
        $ok = $hasCode
            ? $verifier->verifyCode($identityId, $fields['code'])
            : $verifier->verifyRecoveryCode($identityId, $fields['recovery_code']);
        if (!$ok) {
            $this->refused($client, $identityId, 'disable', $hasCode ? 'wrong_code' : 'wrong_recovery_code');
            throw new InvalidMfaInput([$hasCode ? 'code' : 'recovery_code' => 'incorrect']);
        }

        $this->mfa->disable($identityId);
        $this->audit->record(AuditEventType::MFA_DISABLED, AuditEvent::SUCCESS, $client, $identityId, details: ['method' => $hasCode ? 'totp' : 'recovery_code']);
    }

    /** @return list<string> */
    private function newRecoveryCodes(string $identityId, MfaCrypto $crypto): array
    {
        $codes = RecoveryCodes::generate();
        $hashes = [];
        foreach ($codes as $code) {
            $hashes[] = (string) $crypto->recoveryCodes->hash($code);
        }
        $this->mfa->replaceRecoveryCodes($identityId, $hashes);

        return $codes;
    }

    private function assertPassword(string $identityId, #[\SensitiveParameter] string $password, ClientContext $client, string $action): void
    {
        $this->throttle('mfa:password:' . $identityId, self::PASSWORD_LIMIT);
        $hash = $this->identities->passwordHash($identityId);
        if ($hash === null || !$this->hasher->verify($password, $hash)) {
            // A wrong password inside a live session may mean a stolen session (T-15).
            $this->refused($client, $identityId, $action, 'wrong_password');
            throw new InvalidMfaInput(['password' => 'incorrect']);
        }
    }

    private function refused(ClientContext $client, string $identityId, string $action, string $reason): void
    {
        $this->audit->record(AuditEventType::MFA_CHANGE_REFUSED, AuditEvent::FAILURE, $client, $identityId, details: ['action' => $action, 'reason' => $reason]);
    }

    private function throttle(string $key, int $limit): void
    {
        $retry = $this->limiter->hit($key, $limit, self::WINDOW_SECONDS);
        if ($retry > 0) {
            throw new MfaThrottled($retry);
        }
    }

    private function crypto(): MfaCrypto
    {
        return $this->crypto ?? throw new MfaUnavailable();
    }

    /**
     * Exactly the named string fields; anything else makes the request invalid.
     *
     * @param array<array-key, mixed> $data
     * @param list<string> $names
     * @return array<string, string>
     */
    public static function fields(#[\SensitiveParameter] array $data, array $names): array
    {
        $errors = [];
        foreach (array_keys($data) as $key) {
            if (!in_array($key, $names, true)) {
                $errors[is_string($key) && preg_match('/^[a-z_]{1,32}$/', $key) === 1 ? $key : '_unknown'] = 'unknown_field';
            }
        }
        $values = [];
        foreach ($names as $name) {
            $value = $data[$name] ?? null;
            if (!is_string($value) || $value === '' || strlen($value) > 1024) {
                $errors[$name] = 'required';
            } else {
                $values[$name] = $value;
            }
        }
        if ($errors !== []) {
            throw new InvalidMfaInput($errors);
        }

        return $values;
    }
}
