<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Application\Identity\Registration;

use PaxofiCloud\Tests\Support\AuditFixtures;
use PaxofiCloud\Application\Audit\ClientContext;
use PHPUnit\Framework\TestCase;
use PaxofiCloud\Application\Identity\Challenge\ChallengeGate;
use PaxofiCloud\Application\Identity\Registration\RegisterCustomer;
use PaxofiCloud\Application\Identity\Registration\RegistrationThrottled;
use PaxofiCloud\Application\Identity\Registration\VerificationTokenInvalid;
use PaxofiCloud\Application\Identity\Registration\VerifyEmail;
use PaxofiCloud\Tests\Support\CountingPasswordHasher;
use PaxofiCloud\Tests\Support\ImmediateTransactions;
use PaxofiCloud\Tests\Support\InMemoryIdentityStore;
use PaxofiCloud\Tests\Support\MutableClock;
use PaxofiCloud\Tests\Support\RecordingOutbox;
use PaxofiCloud\Tests\Support\ScriptedBreachCheck;
use PaxofiCloud\Tests\Support\ScriptedHumanVerification;
use PaxofiCloud\Tests\Support\ScriptedRateLimiter;

/** SRS IAM-002; threat model T-03 (EmailVerificationTokenTest). */
final class VerifyEmailTest extends TestCase
{
    private InMemoryIdentityStore $store;
    private RecordingOutbox $outbox;
    private MutableClock $clock;
    private RegisterCustomer $register;
    private VerifyEmail $verify;

    protected function setUp(): void
    {
        $this->store = new InMemoryIdentityStore();
        $this->outbox = new RecordingOutbox();
        $this->clock = new MutableClock(new \DateTimeImmutable('2026-10-26 09:00:00 UTC'));
        $this->register = new RegisterCustomer($this->store, $this->outbox, new CountingPasswordHasher(), new ImmediateTransactions(), new ScriptedRateLimiter(), $this->clock, '2026-10-01', new ScriptedBreachCheck(), new ChallengeGate(new ScriptedHumanVerification()), AuditFixtures::trail());
        $this->verify = new VerifyEmail($this->store, new ImmediateTransactions(), new ScriptedRateLimiter(), $this->clock, AuditFixtures::trail());
    }

    public function testAValidLinkActivatesTheIdentityOnce(): void
    {
        $token = $this->registerAndGetToken();

        $this->verify->verify($token, ClientContext::ip('198.51.100.7'));

        $identity = array_values($this->store->identities)[0];
        self::assertSame('active', $identity['status']);
        self::assertEquals(new \DateTimeImmutable('2026-10-26 09:00:00 UTC'), $identity['verified_at']);

        $this->expectException(VerificationTokenInvalid::class);
        $this->verify->verify($token, ClientContext::ip('198.51.100.7'));
    }

    public function testExpiredLinkIsRefused(): void
    {
        $token = $this->registerAndGetToken();
        $this->clock->advance('+24 hours');

        $this->expectException(VerificationTokenInvalid::class);
        try {
            $this->verify->verify($token, ClientContext::ip('198.51.100.7'));
        } finally {
            self::assertSame('pending_verification', array_values($this->store->identities)[0]['status']);
        }
    }

    public function testLinkStillWorksJustBeforeExpiry(): void
    {
        $token = $this->registerAndGetToken();
        $this->clock->advance('+86399 seconds');

        $this->verify->verify($token, ClientContext::ip('198.51.100.7'));

        self::assertSame('active', array_values($this->store->identities)[0]['status']);
    }

    /** @return iterable<string, array{mixed}> */
    public static function malformedTokens(): iterable
    {
        yield 'not a string' => [['x']];
        yield 'too short' => ['abc'];
        yield 'bad characters' => [str_repeat('a', 42) . '='];
        yield 'sql-ish' => ["' OR 1=1 --" . str_repeat('a', 32)];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedTokens')]
    public function testMalformedTokensFailLikeAnyOtherBadLink(mixed $token): void
    {
        $this->expectException(VerificationTokenInvalid::class);
        $this->verify->verify($token, ClientContext::ip('198.51.100.7'));
    }

    public function testUnknownWellFormedTokenIsRefused(): void
    {
        $this->expectException(VerificationTokenInvalid::class);
        $this->verify->verify(RegisterCustomer::newToken(), ClientContext::ip('198.51.100.7'));
    }

    public function testATokenForAnIdentityThatIsNoLongerPendingDoesNotReactivateIt(): void
    {
        $token = $this->registerAndGetToken();
        $id = (string) array_key_first($this->store->identities);
        $this->store->identities[$id]['status'] = 'suspended';

        $this->expectException(VerificationTokenInvalid::class);
        try {
            $this->verify->verify($token, ClientContext::ip('198.51.100.7'));
        } finally {
            self::assertSame('suspended', $this->store->identities[$id]['status']);
        }
    }

    public function testVerificationIsRateLimitedPerIp(): void
    {
        for ($i = 0; $i < VerifyEmail::PER_IP_LIMIT; $i++) {
            try {
                $this->verify->verify('bad', ClientContext::ip('203.0.113.9'));
            } catch (VerificationTokenInvalid) {
            }
        }

        $this->expectException(RegistrationThrottled::class);
        $this->verify->verify('bad', ClientContext::ip('203.0.113.9'));
    }

    private function registerAndGetToken(): string
    {
        $this->register->register(RegistrationInputTest::valid(), ClientContext::ip('198.51.100.7'));

        return $this->outbox->queued[0]['parameters']['token'];
    }
}
