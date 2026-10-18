<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Application\Identity\Registration;

use PHPUnit\Framework\TestCase;
use PaxofiCloud\Application\Contracts\HumanCheck;
use PaxofiCloud\Application\Email\EmailAddress;
use PaxofiCloud\Application\Email\EmailTemplates;
use PaxofiCloud\Application\Identity\Challenge\ChallengeGate;
use PaxofiCloud\Application\Identity\Challenge\ChallengeRequired;
use PaxofiCloud\Application\Identity\Challenge\ChallengeUnavailable;
use PaxofiCloud\Application\Identity\Registration\InvalidRegistration;
use PaxofiCloud\Application\Identity\Registration\RegisterCustomer;
use PaxofiCloud\Application\Identity\Registration\RegistrationThrottled;
use PaxofiCloud\Tests\Support\CountingPasswordHasher;
use PaxofiCloud\Tests\Support\FixedClock;
use PaxofiCloud\Tests\Support\ImmediateTransactions;
use PaxofiCloud\Tests\Support\InMemoryIdentityStore;
use PaxofiCloud\Tests\Support\RecordingOutbox;
use PaxofiCloud\Tests\Support\ScriptedBreachCheck;
use PaxofiCloud\Tests\Support\ScriptedHumanVerification;
use PaxofiCloud\Tests\Support\ScriptedRateLimiter;

/** SRS IAM-001, IAM-003; threat model T-01, T-02, T-03, T-06, T-46, T-47. */
final class RegisterCustomerTest extends TestCase
{
    private InMemoryIdentityStore $store;
    private RecordingOutbox $outbox;
    private CountingPasswordHasher $hasher;
    private ImmediateTransactions $transactions;
    private ScriptedRateLimiter $limiter;
    private RegisterCustomer $register;

    protected function setUp(): void
    {
        $this->store = new InMemoryIdentityStore();
        $this->outbox = new RecordingOutbox();
        $this->hasher = new CountingPasswordHasher();
        $this->transactions = new ImmediateTransactions();
        $this->limiter = new ScriptedRateLimiter();
        $this->register = new RegisterCustomer($this->store, $this->outbox, $this->hasher, $this->transactions, $this->limiter, new FixedClock(new \DateTimeImmutable('2026-10-26 09:00:00 UTC')), '2026-10-01', new ScriptedBreachCheck(), new ChallengeGate(new ScriptedHumanVerification()));
    }

    public function testNewAddressCreatesAPendingIdentityAndQueuesAVerificationLink(): void
    {
        $this->register->register(RegistrationInputTest::valid(), '198.51.100.7');

        $identity = array_values($this->store->identities)[0];
        self::assertSame('pending_verification', $identity['status']);
        self::assertSame((new EmailAddress('ada@example.ng'))->hash(), $identity['lookup']);
        self::assertSame('2026-10-01', $identity['terms']);
        self::assertTrue($this->hasher->verify('Sup3r secret passphrase', $identity['password_hash']));

        self::assertCount(1, $this->outbox->queued);
        $mail = $this->outbox->queued[0];
        self::assertSame([EmailTemplates::VERIFY_EMAIL, 'ada@example.ng', 'Ada Okafor'], [$mail['template'], $mail['to'], $mail['parameters']['name']]);
        $token = $mail['parameters']['token'];
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $token);
        self::assertSame([hash('sha256', $token)], array_keys($this->store->tokens), 'Only the token hash is stored (D-2)');
        self::assertEquals(new \DateTimeImmutable('2026-10-27 09:00:00 UTC'), $this->store->tokens[hash('sha256', $token)]['expires_at']);
    }

    public function testABreachedPasswordIsRefusedBeforeHashingOrStoringAnything(): void
    {
        $register = new RegisterCustomer($this->store, $this->outbox, $this->hasher, $this->transactions, $this->limiter, new FixedClock(new \DateTimeImmutable('2026-10-26 09:00:00 UTC')), '2026-10-01', new ScriptedBreachCheck(true), new ChallengeGate(new ScriptedHumanVerification()));

        try {
            $register->register(RegistrationInputTest::valid(), '198.51.100.7');
            self::fail('Expected the breached password to be refused.');
        } catch (InvalidRegistration $e) {
            self::assertSame(['password' => 'breached'], $e->errors);
        }
        self::assertSame(0, $this->hasher->hashes);
        self::assertSame([], $this->store->identities);
        self::assertSame([], $this->outbox->queued);
    }

    public function testRegistrationContinuesWhenTheBreachCheckIsUnavailable(): void
    {
        $check = new ScriptedBreachCheck(null);
        $register = new RegisterCustomer($this->store, $this->outbox, $this->hasher, $this->transactions, $this->limiter, new FixedClock(new \DateTimeImmutable('2026-10-26 09:00:00 UTC')), '2026-10-01', $check, new ChallengeGate(new ScriptedHumanVerification()));

        $register->register(RegistrationInputTest::valid(), '198.51.100.7');

        self::assertSame(1, $check->calls);
        self::assertCount(1, $this->store->identities, 'T-06 fails open');
    }

    public function testThrottledAttemptsNeverReachTheBreachCheck(): void
    {
        $check = new ScriptedBreachCheck();
        $register = new RegisterCustomer($this->store, $this->outbox, $this->hasher, $this->transactions, $this->limiter, new FixedClock(new \DateTimeImmutable('2026-10-26 09:00:00 UTC')), '2026-10-01', $check, new ChallengeGate(new ScriptedHumanVerification()));
        for ($i = 0; $i < RegisterCustomer::PER_EMAIL_LIMIT; $i++) {
            $register->register(RegistrationInputTest::valid(), "198.51.100.{$i}");
        }

        try {
            $register->register(RegistrationInputTest::valid(), '198.51.100.99');
            self::fail('Expected throttling.');
        } catch (RegistrationThrottled) {
        }
        self::assertSame(RegisterCustomer::PER_EMAIL_LIMIT, $check->calls);
    }

    public function testActiveAddressGetsAnAccountExistsEmailAndNothingChanges(): void
    {
        $this->register->register(RegistrationInputTest::valid(), '198.51.100.7');
        $id = array_key_first($this->store->identities);
        $this->store->identities[$id]['status'] = 'active';
        $before = $this->store->identities;
        $writes = $this->store->writes;

        $this->register->register(['password' => 'a different passphrase'] + RegistrationInputTest::valid(), '198.51.100.8');

        self::assertSame($before, $this->store->identities);
        self::assertSame($writes, $this->store->writes);
        self::assertSame(EmailTemplates::ACCOUNT_EXISTS, $this->outbox->queued[1]['template']);
        self::assertArrayNotHasKey('token', $this->outbox->queued[1]['parameters']);
    }

    public function testEveryPathDoesTheSameExpensiveWork(): void
    {
        $this->register->register(RegistrationInputTest::valid(), '198.51.100.7');
        $id = (string) array_key_first($this->store->identities);
        $this->store->identities[$id]['status'] = 'active';
        $this->register->register(RegistrationInputTest::valid(), '198.51.100.8');

        self::assertSame(2, $this->hasher->hashes, 'D-7: an existing address still pays for one Argon2id hash');
        self::assertCount(2, $this->outbox->queued, 'and still queues exactly one email');
    }

    public function testRepeatRegistrationOfAPendingAddressKeepsOnlyTheNewestSubmission(): void
    {
        $this->register->register(['password' => 'attacker passphrase'] + RegistrationInputTest::valid(), '203.0.113.9');
        $firstToken = $this->outbox->queued[0]['parameters']['token'];

        $this->register->register(['full_name' => 'Ada Real', 'password' => 'victim passphrase!'] + RegistrationInputTest::valid(), '198.51.100.7');
        $secondToken = $this->outbox->queued[1]['parameters']['token'];

        self::assertCount(1, $this->store->identities);
        $identity = array_values($this->store->identities)[0];
        self::assertTrue($this->hasher->verify('victim passphrase!', $identity['password_hash']), 'T-46: newest password wins');
        self::assertSame('Ada Real', $identity['full_name']);
        self::assertArrayNotHasKey(hash('sha256', $firstToken), $this->store->tokens, 'The older link is dead');
        self::assertArrayHasKey(hash('sha256', $secondToken), $this->store->tokens);
    }

    public function testPerIpLimitBlocksTheSixthAttemptBeforeHashing(): void
    {
        for ($i = 0; $i < RegisterCustomer::PER_IP_LIMIT; $i++) {
            $this->register->register(['email' => "user{$i}@example.ng"] + RegistrationInputTest::valid(), '198.51.100.7');
        }

        try {
            $this->register->register(['email' => 'user9@example.ng'] + RegistrationInputTest::valid(), '198.51.100.7');
            self::fail('Expected throttling.');
        } catch (RegistrationThrottled $e) {
            self::assertSame(RegisterCustomer::WINDOW_SECONDS, $e->retryAfterSeconds);
        }
        self::assertSame(RegisterCustomer::PER_IP_LIMIT, $this->hasher->hashes);
        self::assertCount(RegisterCustomer::PER_IP_LIMIT, $this->outbox->queued);
    }

    public function testPerEmailLimitStopsEmailBombingFromManyAddresses(): void
    {
        for ($i = 0; $i < RegisterCustomer::PER_EMAIL_LIMIT; $i++) {
            $this->register->register(RegistrationInputTest::valid(), "198.51.100.{$i}");
        }

        $this->expectException(RegistrationThrottled::class);
        $this->register->register(RegistrationInputTest::valid(), '198.51.100.200');
    }

    public function testRateLimitKeysContainNoRawIpOrAddress(): void
    {
        $this->register->register(RegistrationInputTest::valid(), '198.51.100.7');

        $keys = implode(' ', array_keys($this->limiter->hits));
        self::assertStringNotContainsString('198.51.100.7', $keys);
        self::assertStringNotContainsString('ada@', $keys);
    }

    public function testInvalidInputIsRejectedBeforeCountingOrHashing(): void
    {
        try {
            $this->register->register(['role' => 'admin'] + RegistrationInputTest::valid(), '198.51.100.7');
            self::fail('Expected validation failure.');
        } catch (InvalidRegistration) {
        }

        self::assertSame([], $this->limiter->hits);
        self::assertSame(0, $this->hasher->hashes);
        self::assertSame([], $this->store->identities);
    }

    public function testACollisionOnTheUniqueIndexIsRetriedOnce(): void
    {
        $this->transactions->failures = [self::pdoException('23000')];

        $this->register->register(RegistrationInputTest::valid(), '198.51.100.7');

        self::assertSame(2, $this->transactions->count);
        self::assertCount(1, $this->store->identities);
    }

    public function testOtherDatabaseErrorsAreNotRetried(): void
    {
        $this->transactions->failures = [self::pdoException('HY000')];

        $this->expectException(\PDOException::class);
        try {
            $this->register->register(RegistrationInputTest::valid(), '198.51.100.7');
        } finally {
            self::assertSame(1, $this->transactions->count);
        }
    }

    private static function pdoException(string $sqlState): \PDOException
    {
        $e = new \PDOException('database error');
        (new \ReflectionProperty(\Exception::class, 'code'))->setValue($e, $sqlState);

        return $e;
    }

    public function testAFailedChallengeStopsRegistrationBeforeHashingOrStoring(): void
    {
        $human = new ScriptedHumanVerification(HumanCheck::Failed);
        $register = new RegisterCustomer($this->store, $this->outbox, $this->hasher, $this->transactions, $this->limiter, new FixedClock(new \DateTimeImmutable('2026-10-26 09:00:00 UTC')), '2026-10-01', new ScriptedBreachCheck(), new ChallengeGate($human));

        try {
            $register->register(['turnstile_token' => 'tok-1'] + RegistrationInputTest::valid(), '198.51.100.7');
            self::fail('Expected the challenge to be required.');
        } catch (ChallengeRequired) {
        }
        self::assertSame([['token' => 'tok-1', 'action' => 'register', 'ip' => '198.51.100.7']], $human->calls);
        self::assertSame(0, $this->hasher->hashes);
        self::assertSame([], $this->store->identities);
        self::assertSame([], $this->outbox->queued);
    }

    public function testAnUnreachableChallengeProviderFailsClosed(): void
    {
        $register = new RegisterCustomer($this->store, $this->outbox, $this->hasher, $this->transactions, $this->limiter, new FixedClock(new \DateTimeImmutable('2026-10-26 09:00:00 UTC')), '2026-10-01', new ScriptedBreachCheck(), new ChallengeGate(new ScriptedHumanVerification(HumanCheck::Unavailable)));

        $this->expectException(ChallengeUnavailable::class);
        $register->register(RegistrationInputTest::valid(), '198.51.100.7');
    }

    public function testRateLimitsRunBeforeTheChallengeIsVerified(): void
    {
        $human = new ScriptedHumanVerification();
        $register = new RegisterCustomer($this->store, $this->outbox, $this->hasher, $this->transactions, $this->limiter, new FixedClock(new \DateTimeImmutable('2026-10-26 09:00:00 UTC')), '2026-10-01', new ScriptedBreachCheck(), new ChallengeGate($human));
        $this->limiter->hits['register:ip:' . hash('sha256', '198.51.100.7')] = RegisterCustomer::PER_IP_LIMIT;

        try {
            $register->register(RegistrationInputTest::valid(), '198.51.100.7');
            self::fail('Expected the IP limit to apply.');
        } catch (RegistrationThrottled) {
        }
        self::assertSame([], $human->calls, 'No outbound verification for throttled requests');
    }
}
