<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Support;

use PaxofiCloud\Application\Staff\Passkeys\StaffEnrolment;
use PaxofiCloud\Application\Staff\Passkeys\StaffInvitations;
use PaxofiCloud\Application\Staff\Passkeys\StaffPasskeySignIn;
use PaxofiCloud\Application\Staff\StaffAuthorizer;
use PaxofiCloud\Integration\WebAuthn\WebAuthnLibCeremonies;

/** The staff passkey services wired to in-memory stores and the real WebAuthn verifier. */
final class StaffPasskeyFixtures
{
    public const string ORIGIN = 'https://admin.example.test';

    public InMemoryStaffDirectory $directory;
    public InMemoryStaffPasskeyStore $store;
    public InMemoryCeremonyStore $ceremonyStore;
    public InMemoryAuditLog $audit;
    public RecordingOutbox $outbox;
    public ScriptedRateLimiter $limiter;
    public MutableClock $clock;
    public RecordingLogger $logger;
    public StaffInvitations $invitations;
    public StaffEnrolment $enrolment;
    public StaffPasskeySignIn $signIn;

    public function __construct()
    {
        $this->directory = new InMemoryStaffDirectory();
        $this->store = new InMemoryStaffPasskeyStore($this->directory);
        $this->ceremonyStore = new InMemoryCeremonyStore();
        $this->audit = new InMemoryAuditLog();
        $this->outbox = new RecordingOutbox();
        $this->limiter = new ScriptedRateLimiter();
        $this->clock = new MutableClock(new \DateTimeImmutable('2026-10-26 09:00:00 UTC'));
        $this->logger = new RecordingLogger();
        $trail = AuditFixtures::trail($this->audit);
        $ceremonies = new WebAuthnLibCeremonies(self::ORIGIN);
        $this->invitations = new StaffInvitations($this->store, $this->directory, new StaffAuthorizer($this->directory, $trail), $this->directory, $this->outbox, $this->clock, $trail);
        $this->enrolment = new StaffEnrolment($this->store, $ceremonies, $this->ceremonyStore, $this->directory, $this->limiter, $this->clock, $trail, $this->directory, $this->outbox, $this->logger);
        $this->signIn = new StaffPasskeySignIn($this->store, $ceremonies, $this->ceremonyStore, $this->limiter, $this->clock, $trail, $this->logger);
    }

    /** The invitation token most recently queued by email. */
    public function lastToken(): string
    {
        $last = $this->outbox->queued[count($this->outbox->queued) - 1] ?? null;
        $token = $last['parameters']['token'] ?? null;
        if (!is_string($token)) {
            throw new \LogicException('No invitation queued.');
        }

        return $token;
    }

    /** @return array{passkeys: int, active: bool} */
    public function enrol(string $token, SoftwareAuthenticator $authenticator, string $label = ''): array
    {
        $begun = $this->enrolment->begin($token, AuditFixtures::client());

        return $this->enrolment->finish($token, $begun['ceremony_id'], $authenticator->register($begun['options']), $label, AuditFixtures::client());
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
