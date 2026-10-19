<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Support;

use PaxofiCloud\Application\Contracts\PasswordRehashPolicy;
use PaxofiCloud\Application\Identity\Mfa\Totp;
use PaxofiCloud\Application\Staff\Passkeys\StaffInvitations;
use PaxofiCloud\Application\Staff\Passkeys\StaffPasskeyRegistration;
use PaxofiCloud\Application\Staff\Passkeys\StaffPasskeySignIn;
use PaxofiCloud\Application\Staff\Setup\StaffSignInSetup;
use PaxofiCloud\Application\Staff\SignIn\StaffCodeSignIn;
use PaxofiCloud\Application\Staff\SignIn\StaffSecondFactor;
use PaxofiCloud\Application\Staff\StaffAuthorizer;
use PaxofiCloud\Integration\WebAuthn\WebAuthnLibCeremonies;

/**
 * The staff sign-in services wired to in-memory stores, the real TOTP and
 * crypto code and the real WebAuthn verifier (§6.17, §6.20).
 */
final class StaffPasskeyFixtures
{
    public const string ORIGIN = 'https://admin.example.test';
    public const string PASSWORD = 'correct horse battery staple';

    public InMemoryStaffDirectory $directory;
    public InMemoryStaffPasskeyStore $store;
    public InMemoryStaffCredentialStore $credentials;
    public InMemoryCeremonyStore $ceremonyStore;
    public InMemoryCeremonyStore $tickets;
    public InMemoryAuditLog $audit;
    public RecordingOutbox $outbox;
    public ScriptedRateLimiter $limiter;
    public MutableClock $clock;
    public RecordingLogger $logger;
    public CountingPasswordHasher $hasher;
    public ScriptedBreachCheck $breaches;
    public StaffInvitations $invitations;
    public StaffSignInSetup $setup;
    public StaffCodeSignIn $codes;
    public StaffPasskeyRegistration $registration;
    public StaffPasskeySignIn $signIn;

    /** @var array<string, string> plain TOTP secret per staff ID, as the member's authenticator app holds it */
    public array $secrets = [];

    public function __construct(?ScriptedBreachCheck $breaches = null)
    {
        $this->directory = new InMemoryStaffDirectory();
        $this->store = new InMemoryStaffPasskeyStore($this->directory);
        $this->credentials = new InMemoryStaffCredentialStore($this->store, $this->directory);
        $this->ceremonyStore = new InMemoryCeremonyStore();
        $this->tickets = new InMemoryCeremonyStore();
        $this->audit = new InMemoryAuditLog();
        $this->outbox = new RecordingOutbox();
        $this->limiter = new ScriptedRateLimiter();
        $this->clock = new MutableClock(new \DateTimeImmutable('2026-10-26 09:00:00 UTC'));
        $this->logger = new RecordingLogger();
        $this->hasher = new CountingPasswordHasher();
        $this->breaches = $breaches ?? new ScriptedBreachCheck(false);
        $trail = AuditFixtures::trail($this->audit);
        $ceremonies = new WebAuthnLibCeremonies(self::ORIGIN);
        $crypto = MfaFixtures::crypto();
        $secondFactor = new StaffSecondFactor($this->credentials, $crypto, $this->clock);
        $neverRehash = new class implements PasswordRehashPolicy {
            public function needsRehash(string $hash): bool
            {
                return false;
            }
        };
        $this->invitations = new StaffInvitations($this->store, $this->directory, new StaffAuthorizer($this->directory, $trail), $this->directory, $this->outbox, $this->clock, $trail);
        $this->setup = new StaffSignInSetup($this->credentials, $this->store, $crypto, $this->hasher, $this->breaches, $this->directory, $this->limiter, $this->clock, $trail, $this->directory, $this->outbox, $this->logger);
        $this->codes = new StaffCodeSignIn($this->credentials, $this->hasher, $neverRehash, $secondFactor, $this->tickets, $this->limiter, $this->outbox, $this->clock, $trail, $this->logger);
        $this->registration = new StaffPasskeyRegistration($this->store, $ceremonies, $this->ceremonyStore, $secondFactor, $this->directory, $this->limiter, $this->clock, $trail);
        $this->signIn = new StaffPasskeySignIn($this->store, $ceremonies, $this->ceremonyStore, $this->limiter, $this->clock, $trail, $this->logger);
    }

    /** The invitation token most recently queued by email. */
    public function lastToken(): string
    {
        foreach (array_reverse($this->outbox->queued) as $email) {
            $token = $email['parameters']['token'] ?? null;
            if (is_string($token)) {
                return $token;
            }
        }

        throw new \LogicException('No invitation queued.');
    }

    /** The sign-in code most recently emailed. */
    public function lastEmailCode(): string
    {
        foreach (array_reverse($this->outbox->queued) as $email) {
            $code = $email['parameters']['code'] ?? null;
            if (is_string($code)) {
                return $code;
            }
        }

        throw new \LogicException('No sign-in code queued.');
    }

    /**
     * Sets up password and authenticator with the invitation, as the member would.
     *
     * @return list<string> the recovery codes
     */
    public function activate(string $token, string $password = self::PASSWORD): array
    {
        $this->setup->startWithInvitation($token, AuditFixtures::client());
        $staffId = $this->store->openInvitationForUpdate(hash('sha256', $token), $this->clock->now())['staff_id'] ?? throw new \LogicException('dead invitation');
        $secret = $this->scannedSecret($staffId);

        return $this->setup->finishWithInvitation($token, $password, Totp::code($secret, Totp::step($this->clock->now())), AuditFixtures::client());
    }

    /** What the authenticator app holds after scanning the setup QR code. */
    public function scannedSecret(string $staffId): string
    {
        $envelope = $this->credentials->totp[$staffId]['secret_envelope'] ?? throw new \LogicException('no secret');

        return $this->secrets[$staffId] = MfaFixtures::crypto()->secrets->decrypt($envelope, StaffSecondFactor::associatedData($staffId));
    }

    /** A fresh authenticator code: the clock moves to the next time step first, as codes work once per step. */
    public function totpCode(string $staffId): string
    {
        $this->clock->advance('+30 seconds');

        return Totp::code($this->secrets[$staffId] ?? throw new \LogicException('no secret'), Totp::step($this->clock->now()));
    }

    /** All three steps; returns the staff ID. */
    public function signInWithCodes(string $email, string $staffId, string $password = self::PASSWORD): string
    {
        $step = $this->codes->password($email, $password, AuditFixtures::client());
        $step = $this->codes->authenticatorCode($step->ticket, $this->totpCode($staffId), AuditFixtures::client());

        return $this->codes->emailCode($step->ticket, $this->lastEmailCode(), AuditFixtures::client());
    }

    /** @return array{passkeys: int} */
    public function addPasskey(string $staffId, SoftwareAuthenticator $authenticator, string $label = ''): array
    {
        $begun = $this->registration->begin($staffId, $this->totpCode($staffId), AuditFixtures::client());

        return $this->registration->finish($staffId, $begun['ceremony_id'], $authenticator->register($begun['options']), $label, AuditFixtures::client());
    }

    public function signInWith(SoftwareAuthenticator $authenticator): string
    {
        $begun = $this->signIn->begin(AuditFixtures::client());
        $handle = $this->handleOf($authenticator);

        return $this->signIn->finish($begun['ceremony_id'], $authenticator->assert($begun['options'], $handle), AuditFixtures::client());
    }

    public function handleOf(SoftwareAuthenticator $authenticator): string
    {
        foreach ($this->store->passkeys as $row) {
            if ($row['passkey']->credentialId === $authenticator->credentialId) {
                return $this->store->profiles[$row['staff_id']]['handle'];
            }
        }

        return random_bytes(32);
    }
}
