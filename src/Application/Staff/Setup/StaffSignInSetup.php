<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Staff\Setup;

use Paxofi\Core\Contracts\Logger;
use Paxofi\Core\Contracts\PasswordHasher;
use Paxofi\Core\Contracts\TransactionManager;
use PaxofiCloud\Application\Audit\AuditEvent;
use PaxofiCloud\Application\Audit\AuditEventType;
use PaxofiCloud\Application\Audit\AuditTrail;
use PaxofiCloud\Application\Audit\ClientContext;
use PaxofiCloud\Application\Contracts\BreachedPasswordCheck;
use PaxofiCloud\Application\Contracts\Clock;
use PaxofiCloud\Application\Contracts\EmailOutbox;
use PaxofiCloud\Application\Contracts\RateLimiter;
use PaxofiCloud\Application\Contracts\StaffCredentialStore;
use PaxofiCloud\Application\Contracts\StaffDirectory;
use PaxofiCloud\Application\Contracts\StaffPasskeyStore;
use PaxofiCloud\Application\Email\EmailAddress;
use PaxofiCloud\Application\Email\EmailTemplates;
use PaxofiCloud\Application\Identity\Mfa\Base32;
use PaxofiCloud\Application\Identity\Mfa\MfaCrypto;
use PaxofiCloud\Application\Identity\Mfa\RecoveryCodes;
use PaxofiCloud\Application\Identity\Mfa\Totp;
use PaxofiCloud\Application\Staff\Passkeys\PasskeyTokens;
use PaxofiCloud\Application\Staff\SignIn\StaffSecondFactor;
use PaxofiCloud\Domain\Staff\Permission;
use PaxofiCloud\Domain\Staff\StaffRole;

/**
 * Setting up password and authenticator sign-in (§6.20): with an invitation
 * (which then activates the member), or after a passkey sign-in for a member
 * who has no password yet. Finishing needs a valid authenticator code, so a
 * member cannot end up with a secret they never scanned.
 */
final readonly class StaffSignInSetup
{
    public const int PASSWORD_MIN = 15;
    public const int PASSWORD_MAX = 128;
    public const int IP_LIMIT_PER_HOUR = 30;
    public const string ISSUER = 'PaxofiCloud Admin';

    public function __construct(
        private StaffCredentialStore $credentials,
        private StaffPasskeyStore $invitations,
        private ?MfaCrypto $crypto,
        private PasswordHasher $hasher,
        private BreachedPasswordCheck $breaches,
        private TransactionManager $transactions,
        private RateLimiter $limiter,
        private Clock $clock,
        private AuditTrail $audit,
        private StaffDirectory $directory,
        private EmailOutbox $outbox,
        private Logger $logger,
    ) {
    }

    /**
     * @return array{full_name: string, email: string, secret: string, uri: string}
     * @throws StaffSetupRefused
     */
    public function startWithInvitation(#[\SensitiveParameter] string $token, ClientContext $client): array
    {
        $crypto = $this->ready($client);
        $invitation = $this->invitation($token, $client);

        return $this->newSecret($crypto, $invitation['staff_id'], $invitation['email'], $invitation['full_name']);
    }

    /**
     * @return list<string> the recovery codes, shown once
     * @throws StaffSetupRefused
     * @throws InvalidStaffSetup
     */
    public function finishWithInvitation(#[\SensitiveParameter] string $token, #[\SensitiveParameter] string $password, #[\SensitiveParameter] string $code, ClientContext $client): array
    {
        $crypto = $this->ready($client);
        $tokenHash = hash('sha256', $token);
        $invitation = $this->invitation($token, $client);
        [$hash, $step] = $this->check($crypto, $invitation['staff_id'], $password, $code);

        $codes = $this->transactions->transaction(function () use ($crypto, $tokenHash, $invitation, $hash, $step): ?array {
            $now = $this->clock->now();
            $locked = $this->invitations->openInvitationForUpdate($tokenHash, $now);
            if ($locked === null || $locked['staff_id'] !== $invitation['staff_id']) {
                return null;
            }
            $codes = $this->complete($crypto, $invitation['staff_id'], $hash, $step);
            if ($codes !== null) {
                $this->invitations->completeEnrolment($tokenHash, $invitation['staff_id'], $now);
            }

            return $codes;
        });
        if ($codes === null) {
            $this->refuse(StaffSetupRefused::INVITATION, $client, $invitation['staff_id']);
        }

        $this->audit->record(AuditEventType::STAFF_SIGN_IN_SET_UP, AuditEvent::SUCCESS, $client, details: ['subject' => $invitation['staff_id'], 'via' => 'invitation']);
        $this->audit->record(AuditEventType::STAFF_ACTIVATED, AuditEvent::SUCCESS, $client, details: ['subject' => $invitation['staff_id']]);
        $this->alertManagers($invitation['staff_id'], $invitation['full_name']);

        return $codes;
    }

    /**
     * For a member who signed in with a passkey and has no password yet.
     *
     * @return array{full_name: string, email: string, secret: string, uri: string}
     * @throws StaffSetupRefused
     */
    public function startForMember(string $staffId, ClientContext $client): array
    {
        $crypto = $this->ready($client);
        $member = $this->memberWithoutPassword($staffId, $client);

        return $this->newSecret($crypto, $staffId, $member['email'], $member['full_name']);
    }

    /**
     * @return list<string>
     * @throws StaffSetupRefused
     * @throws InvalidStaffSetup
     */
    public function finishForMember(string $staffId, #[\SensitiveParameter] string $password, #[\SensitiveParameter] string $code, ClientContext $client): array
    {
        $crypto = $this->ready($client);
        $this->memberWithoutPassword($staffId, $client);
        [$hash, $step] = $this->check($crypto, $staffId, $password, $code);

        $codes = $this->transactions->transaction(fn (): ?array => $this->complete($crypto, $staffId, $hash, $step));
        if ($codes === null) {
            $this->refuse(StaffSetupRefused::NOT_ALLOWED, $client, $staffId);
        }
        $this->audit->record(AuditEventType::STAFF_SIGN_IN_SET_UP, AuditEvent::SUCCESS, $client, details: ['subject' => $staffId, 'via' => 'session']);

        return $codes;
    }

    /** @return array{full_name: string, email: string, secret: string, uri: string} */
    private function newSecret(MfaCrypto $crypto, string $staffId, string $email, string $fullName): array
    {
        $secret = Totp::newSecret();
        // Only an unconfirmed secret is replaced, so starting again never undoes a finished setup.
        $this->credentials->saveTotpSetup($staffId, $crypto->secrets->encrypt($secret, StaffSecondFactor::associatedData($staffId)));

        return ['full_name' => $fullName, 'email' => $email, 'secret' => Base32::encode($secret), 'uri' => Totp::uri($secret, $email, self::ISSUER)];
    }

    /**
     * Field checks first (cheap), then the breach lookup, then the code.
     *
     * @return array{string, int} the password hash and the matched time step
     * @throws InvalidStaffSetup
     */
    private function check(MfaCrypto $crypto, string $staffId, #[\SensitiveParameter] string $password, #[\SensitiveParameter] string $code): array
    {
        $errors = [];
        if (!mb_check_encoding($password, 'UTF-8') || preg_match('/\p{Cc}/u', $password) === 1) {
            $errors['password'] = 'invalid_characters';
        } elseif (mb_strlen($password, 'UTF-8') < self::PASSWORD_MIN) {
            $errors['password'] = 'too_short';
        } elseif (mb_strlen($password, 'UTF-8') > self::PASSWORD_MAX) {
            $errors['password'] = 'too_long';
        }
        $totp = $this->credentials->totp($staffId);
        $step = null;
        if ($totp !== null && !$totp['confirmed']) {
            $secret = $crypto->secrets->decrypt($totp['secret_envelope'], StaffSecondFactor::associatedData($staffId));
            $step = Totp::match($secret, $code, $this->clock->now(), null);
        }
        if ($step === null) {
            $errors['code'] = 'invalid';
        }
        // T-06: the same breached-password check as customers; it fails open (null) with a log.
        if (!isset($errors['password']) && $this->breaches->isBreached($password) === true) {
            $errors['password'] = 'breached';
        }
        if ($errors !== [] || $step === null) {
            throw new InvalidStaffSetup($errors);
        }

        return [$this->hasher->hash($password), $step];
    }

    /** @return list<string>|null the plain recovery codes, or null when the member cannot be set up */
    private function complete(MfaCrypto $crypto, string $staffId, string $passwordHash, int $step): ?array
    {
        $codes = RecoveryCodes::generate();
        $hashes = array_map(static fn (string $c): string => (string) $crypto->recoveryCodes->hash($c), $codes);

        return $this->credentials->completeSetup($staffId, $passwordHash, $step, $hashes, $this->clock->now()) ? $codes : null;
    }

    /**
     * @return array{staff_id: string, email: string, full_name: string, user_handle: string}
     * @throws StaffSetupRefused
     */
    private function invitation(#[\SensitiveParameter] string $token, ClientContext $client): array
    {
        $invitation = PasskeyTokens::isToken($token) ? $this->invitations->openInvitationForUpdate(hash('sha256', $token), $this->clock->now()) : null;
        if ($invitation === null) {
            $this->refuse(StaffSetupRefused::INVITATION, $client);
        }

        return $invitation;
    }

    /**
     * @return array{email: string, full_name: string, status: string, has_password: bool}
     * @throws StaffSetupRefused
     */
    private function memberWithoutPassword(string $staffId, ClientContext $client): array
    {
        $member = $this->credentials->member($staffId);
        if ($member === null || $member['status'] !== 'active' || $member['has_password']) {
            $this->refuse(StaffSetupRefused::NOT_ALLOWED, $client, $staffId);
        }

        return $member;
    }

    /** @throws StaffSetupRefused */
    private function ready(ClientContext $client): MfaCrypto
    {
        $retry = $this->limiter->hit('staff:setup:ip:' . hash('sha256', $client->ip), self::IP_LIMIT_PER_HOUR, 3600);
        if ($retry > 0) {
            throw new StaffSetupRefused(StaffSetupRefused::THROTTLED, $retry);
        }

        return $this->crypto ?? throw new StaffSetupRefused(StaffSetupRefused::UNAVAILABLE);
    }

    /** @throws StaffSetupRefused */
    private function refuse(string $reason, ClientContext $client, ?string $staffId = null): never
    {
        $details = ['reason' => $reason];
        if ($staffId !== null && preg_match('/^[a-f0-9]{32}$/', $staffId) === 1) {
            $details['subject'] = $staffId;
        }
        $this->audit->record(AuditEventType::STAFF_SIGN_IN_SETUP_REFUSED, AuditEvent::FAILURE, $client, details: $details);

        throw new StaffSetupRefused($reason);
    }

    /**
     * §6.18: every other active staff manager hears about the new member, so an
     * activation from a stolen invitation is noticed. Best effort, after commit.
     */
    private function alertManagers(string $staffId, string $fullName): void
    {
        $managers = array_values(array_filter(
            StaffRole::cases(),
            static fn (StaffRole $r): bool => in_array(Permission::StaffManage, $r->permissions(), true),
        ));
        foreach ($this->directory->activeHoldersOf($managers) as $manager) {
            if ($manager['id'] === $staffId) {
                continue;
            }
            try {
                $this->outbox->enqueue(new EmailAddress($manager['email']), EmailTemplates::STAFF_ACTIVATED, ['name' => $manager['full_name'], 'staff_name' => $fullName]);
            } catch (\Throwable $e) {
                $this->logger->error('staff.activation_alert_failed', ['staff_id' => $staffId, 'error' => $e::class]);
            }
        }
    }
}
