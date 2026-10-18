<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Application\Identity\Registration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PaxofiCloud\Application\Contracts\HumanCheck;
use PaxofiCloud\Application\Email\EmailTemplates;
use PaxofiCloud\Application\Identity\Challenge\ChallengeGate;
use PaxofiCloud\Application\Identity\Challenge\ChallengeRequired;
use PaxofiCloud\Application\Identity\Registration\InvalidRegistration;
use PaxofiCloud\Application\Identity\Registration\RegisterCustomer;
use PaxofiCloud\Application\Identity\Registration\RegistrationThrottled;
use PaxofiCloud\Application\Identity\Registration\ResendVerification;
use PaxofiCloud\Tests\Support\CountingPasswordHasher;
use PaxofiCloud\Tests\Support\FixedClock;
use PaxofiCloud\Tests\Support\ImmediateTransactions;
use PaxofiCloud\Tests\Support\InMemoryIdentityStore;
use PaxofiCloud\Tests\Support\RecordingOutbox;
use PaxofiCloud\Tests\Support\ScriptedBreachCheck;
use PaxofiCloud\Tests\Support\ScriptedHumanVerification;
use PaxofiCloud\Tests\Support\ScriptedRateLimiter;

/** SRS IAM-002; threat model T-46, T-47, T-49. */
final class ResendVerificationTest extends TestCase
{
    private InMemoryIdentityStore $store;
    private RecordingOutbox $outbox;
    private ScriptedRateLimiter $limiter;
    private RegisterCustomer $register;
    private ResendVerification $resend;

    protected function setUp(): void
    {
        $this->store = new InMemoryIdentityStore();
        $this->outbox = new RecordingOutbox();
        $this->limiter = new ScriptedRateLimiter();
        $clock = new FixedClock(new \DateTimeImmutable('2026-10-26 09:00:00 UTC'));
        $transactions = new ImmediateTransactions();
        $this->register = new RegisterCustomer($this->store, $this->outbox, new CountingPasswordHasher(), $transactions, $this->limiter, $clock, '2026-10-01', new ScriptedBreachCheck(), new ChallengeGate(new ScriptedHumanVerification()));
        $this->resend = new ResendVerification($this->store, $this->outbox, $transactions, $this->limiter, $clock, new ChallengeGate(new ScriptedHumanVerification()));
    }

    public function testPendingAddressGetsAFreshLinkAndOlderLinksStopWorking(): void
    {
        $this->register->register(RegistrationInputTest::valid(), '198.51.100.7');
        $first = $this->outbox->queued[0]['parameters']['token'];

        $this->resend->resend(['email' => 'ADA@Example.ng'], '198.51.100.7');

        self::assertCount(2, $this->outbox->queued);
        $mail = $this->outbox->queued[1];
        self::assertSame([EmailTemplates::VERIFY_EMAIL, 'ADA@example.ng', 'Ada Okafor'], [$mail['template'], $mail['to'], $mail['parameters']['name']]);
        $second = $mail['parameters']['token'];
        self::assertNotSame($first, $second);
        self::assertSame([hash('sha256', $second)], array_keys($this->store->tokens), 'Only the newest link works (T-46)');
    }

    public function testActiveAddressGetsTheAccountExistsEmailAndNoToken(): void
    {
        $this->register->register(RegistrationInputTest::valid(), '198.51.100.7');
        $id = array_key_first($this->store->identities);
        $this->store->identities[$id]['status'] = 'active';
        $tokensBefore = $this->store->tokens;

        $this->resend->resend(['email' => 'ada@example.ng'], '198.51.100.7');

        self::assertSame(EmailTemplates::ACCOUNT_EXISTS, $this->outbox->queued[1]['template']);
        self::assertArrayNotHasKey('token', $this->outbox->queued[1]['parameters']);
        self::assertSame($tokensBefore, $this->store->tokens);
    }

    public function testUnknownAddressQueuesNothingAndLooksTheSame(): void
    {
        $this->resend->resend(['email' => 'nobody@example.ng'], '198.51.100.7');

        self::assertSame([], $this->outbox->queued);
        self::assertSame(0, $this->store->writes);
    }

    public function testTheAddressLimitIsSharedWithRegistration(): void
    {
        $this->register->register(RegistrationInputTest::valid(), '198.51.100.1');
        $this->resend->resend(['email' => 'ada@example.ng'], '198.51.100.2');
        $this->resend->resend(['email' => 'ada@example.ng'], '198.51.100.3');

        try {
            $this->resend->resend(['email' => 'ada@example.ng'], '198.51.100.4');
            self::fail('Expected throttling.');
        } catch (RegistrationThrottled $e) {
            self::assertSame(RegisterCustomer::WINDOW_SECONDS, $e->retryAfterSeconds);
        }
        self::assertCount(RegisterCustomer::PER_EMAIL_LIMIT, $this->outbox->queued, 'At most 3 emails an hour to one address (T-47)');
    }

    public function testPerIpLimitCountsBeforeAnyLookup(): void
    {
        for ($i = 0; $i < ResendVerification::PER_IP_LIMIT; $i++) {
            $this->resend->resend(['email' => "user{$i}@example.ng"], '198.51.100.7');
        }

        $this->expectException(RegistrationThrottled::class);
        $this->resend->resend(['email' => 'user99@example.ng'], '198.51.100.7');
    }

    public function testRateLimitKeysContainNoRawIpOrAddress(): void
    {
        $this->resend->resend(['email' => 'ada@example.ng'], '198.51.100.7');

        $keys = implode(' ', array_keys($this->limiter->hits));
        self::assertStringNotContainsString('198.51.100.7', $keys);
        self::assertStringNotContainsString('ada@example.ng', $keys);
    }

    /** @return iterable<string, array{array<array-key, mixed>, array<string, string>}> */
    public static function invalidBodies(): iterable
    {
        yield 'missing email' => [[], ['email' => 'required']];
        yield 'not an address' => [['email' => 'not-an-email'], ['email' => 'invalid']];
        yield 'not a string' => [['email' => ['ada@example.ng']], ['email' => 'required']];
        yield 'extra field' => [['email' => 'ada@example.ng', 'account_id' => '1'], ['account_id' => 'unknown_field']];
    }

    /**
     * @param array<array-key, mixed> $body
     * @param array<string, string> $errors
     */
    #[DataProvider('invalidBodies')]
    public function testInvalidInputIsRejectedBeforeCounting(array $body, array $errors): void
    {
        try {
            $this->resend->resend($body, '198.51.100.7');
            self::fail('Expected invalid input.');
        } catch (InvalidRegistration $e) {
            self::assertSame($errors, $e->errors);
        }
        self::assertSame([], $this->limiter->hits);
    }

    public function testAFailedChallengeSendsNothing(): void
    {
        $this->register->register(RegistrationInputTest::valid(), '198.51.100.7');
        $human = new ScriptedHumanVerification(HumanCheck::Failed);
        $resend = new ResendVerification($this->store, $this->outbox, new ImmediateTransactions(), $this->limiter, new FixedClock(new \DateTimeImmutable('2026-10-26 09:00:00 UTC')), new ChallengeGate($human));

        try {
            $resend->resend(['email' => 'ada@example.ng', 'turnstile_token' => 'tok'], '198.51.100.7');
            self::fail('Expected a challenge.');
        } catch (ChallengeRequired) {
        }
        self::assertCount(1, $this->outbox->queued, 'Only the original sign-up email');
        self::assertSame('resend', $human->calls[0]['action']);
    }
}
