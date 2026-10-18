<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Application\Identity\SignIn;

use PaxofiCloud\Application\Audit\AuditEventType;
use PaxofiCloud\Application\Email\EmailTemplates;
use PaxofiCloud\Tests\Support\AuditFixtures;
use PaxofiCloud\Tests\Support\InMemoryAuditLog;
use PaxofiCloud\Tests\Support\InMemoryIdentityStore;
use PaxofiCloud\Tests\Support\InMemoryKnownDevices;
use PaxofiCloud\Tests\Support\RecordingOutbox;
use PHPUnit\Framework\TestCase;

/** Threat model §6.15, T-54, T-55. */
final class SignInAlertsTest extends TestCase
{
    private InMemoryIdentityStore $identities;
    private RecordingOutbox $outbox;
    private InMemoryKnownDevices $devices;
    private InMemoryAuditLog $audit;

    protected function setUp(): void
    {
        $this->identities = new InMemoryIdentityStore();
        $this->identities->identities['id-1'] = [
            'id' => 'id-1',
            'email' => 'ada@example.ng',
            'lookup' => 'x',
            'full_name' => 'Ada Okafor',
            'phone' => '+2348012345678',
            'password_hash' => 'h',
            'status' => 'active',
            'terms' => '2026-10-01',
            'verified_at' => null,
            'account_id' => 'acct-1',
        ];
        $this->outbox = new RecordingOutbox();
        $this->devices = new InMemoryKnownDevices();
        $this->audit = new InMemoryAuditLog();
    }

    public function testTheDeviceIsStoredOnlyAsAHashAndAuditedWhenNew(): void
    {
        $alerts = AuditFixtures::alerts($this->identities, $this->outbox, $this->devices, audit: $this->audit);
        $token = str_repeat('k', 43);
        $alerts->signedIn('id-1', 'acct-1', AuditFixtures::client(), $token);
        $alerts->signedIn('id-1', 'acct-1', AuditFixtures::client(), $token);

        self::assertSame(['id-1|' . hash('sha256', $token)], array_keys($this->devices->devices), 'never the raw token (T-55)');
        self::assertSame([AuditEventType::SIGN_IN_NEW_DEVICE], $this->audit->types());
        self::assertSame([EmailTemplates::NEW_DEVICE_ALERT, EmailTemplates::SIGN_IN_ALERT], array_column($this->outbox->queued, 'template'));
    }

    public function testAMalformedDeviceTokenCountsAsNewAndIsNotStored(): void
    {
        AuditFixtures::alerts($this->identities, $this->outbox, $this->devices)->signedIn('id-1', 'acct-1', AuditFixtures::client(), 'not a token');

        self::assertSame([], $this->devices->devices);
        self::assertSame(EmailTemplates::NEW_DEVICE_ALERT, $this->outbox->queued[0]['template']);
    }

    public function testFailedSignInsAndSecurityChangesCarryTheSameDetails(): void
    {
        $alerts = AuditFixtures::alerts($this->identities, $this->outbox);
        $alerts->manyFailedSignIns('id-1', AuditFixtures::client('203.0.113.9'), 11);
        $alerts->securityChanged('id-1', AuditFixtures::client(), 'mfa_disabled');

        self::assertEquals(['attempts' => '11', 'ip' => '203.0.113.9'], array_intersect_key($this->outbox->queued[0]['parameters'], ['attempts' => 1, 'ip' => 1]));
        self::assertSame([EmailTemplates::SIGN_IN_ATTEMPTS_ALERT, EmailTemplates::SECURITY_CHANGE], array_column($this->outbox->queued, 'template'));
        self::assertSame('mfa_disabled', $this->outbox->queued[1]['parameters']['change']);
    }

    public function testAnUnknownIdentityGetsNothingAndAFailureNeverThrows(): void
    {
        AuditFixtures::alerts($this->identities, $this->outbox)->signedIn('id-unknown', 'acct-1', AuditFixtures::client(), null);
        self::assertSame([], $this->outbox->queued);

        $failing = new class implements \PaxofiCloud\Application\Contracts\KnownDevices {
            public function remember(string $identityId, string $deviceHash, string $label, \DateTimeImmutable $now): bool
            {
                throw new \RuntimeException('database down');
            }
        };
        $alerts = new \PaxofiCloud\Application\Identity\SignIn\SignInAlerts($failing, $this->identities, $this->outbox, new \PaxofiCloud\Tests\Support\ScriptedRateLimiter(), new \PaxofiCloud\Tests\Support\FixedClock(new \DateTimeImmutable()), $logger = new \PaxofiCloud\Tests\Support\RecordingLogger(), AuditFixtures::trail());
        $alerts->signedIn('id-1', 'acct-1', AuditFixtures::client(), str_repeat('k', 43));

        self::assertSame('notify.enqueue_failed', $logger->records[0]['message'] ?? null);
    }
}
