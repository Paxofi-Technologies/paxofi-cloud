<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Application\Identity\SignIn;

use PHPUnit\Framework\TestCase;
use PaxofiCloud\Application\Contracts\HumanCheck;
use PaxofiCloud\Application\Contracts\PasswordRehashPolicy;
use PaxofiCloud\Application\Email\EmailAddress;
use PaxofiCloud\Application\Identity\Challenge\ChallengeGate;
use PaxofiCloud\Application\Identity\Challenge\ChallengeRequired;
use PaxofiCloud\Application\Identity\Challenge\ChallengeUnavailable;
use PaxofiCloud\Application\Identity\Mfa\CompleteMfaSignIn;
use PaxofiCloud\Application\Identity\Mfa\MfaPending;
use PaxofiCloud\Application\Identity\Mfa\MfaSettings;
use PaxofiCloud\Application\Identity\Session\IssuedSession;
use PaxofiCloud\Application\Identity\Session\Sessions;
use PaxofiCloud\Application\Identity\SignIn\AccountUnavailable;
use PaxofiCloud\Application\Identity\SignIn\EmailNotVerified;
use PaxofiCloud\Application\Identity\SignIn\InvalidCredentials;
use PaxofiCloud\Application\Identity\SignIn\InvalidSignIn;
use PaxofiCloud\Application\Identity\SignIn\SignIn;
use PaxofiCloud\Application\Identity\SignIn\SignInThrottled;
use PaxofiCloud\Infrastructure\Identity\Argon2idPasswordHasher;
use PaxofiCloud\Tests\Support\CountingPasswordHasher;
use PaxofiCloud\Tests\Support\InMemoryIdentityStore;
use PaxofiCloud\Tests\Support\InMemoryMfaChallengeStore;
use PaxofiCloud\Tests\Support\InMemoryMfaStore;
use PaxofiCloud\Tests\Support\InMemorySessionStore;
use PaxofiCloud\Tests\Support\InMemorySignInThrottle;
use PaxofiCloud\Tests\Support\MfaFixtures;
use PaxofiCloud\Tests\Support\MutableClock;
use PaxofiCloud\Tests\Support\RecordingLogger;
use PaxofiCloud\Tests\Support\ScriptedHumanVerification;
use PaxofiCloud\Tests\Support\ScriptedRateLimiter;

/** SRS IAM-005, IAM-006, IAM-009; threat model §6.11, T-07, T-08, T-09, T-11, T-18, D-3, D-7. */
final class SignInTest extends TestCase
{
    private const string PASSWORD = 'Sup3r secret passphrase';
    private const string IP = '198.51.100.7';

    private InMemoryIdentityStore $store;
    private CountingPasswordHasher $hasher;
    private ScriptedRateLimiter $limiter;
    private InMemorySignInThrottle $throttle;
    private InMemorySessionStore $sessionStore;
    private RecordingLogger $logger;
    private ScriptedHumanVerification $human;
    private InMemoryMfaStore $mfaStore;
    private bool $needsRehash = false;
    private SignIn $signIn;

    protected function setUp(): void
    {
        $this->store = new InMemoryIdentityStore();
        $this->hasher = new CountingPasswordHasher();
        $this->limiter = new ScriptedRateLimiter();
        $this->throttle = new InMemorySignInThrottle();
        $this->sessionStore = new InMemorySessionStore();
        $this->logger = new RecordingLogger();
        $this->human = new ScriptedHumanVerification();
        $rehash = new class ($this) implements PasswordRehashPolicy {
            public function __construct(private SignInTest $test)
            {
            }

            public function needsRehash(string $hash): bool
            {
                return $this->test->rehashWanted();
            }
        };
        $clock = new MutableClock(new \DateTimeImmutable('2026-10-26 09:00:00 UTC'));
        $sessions = new Sessions($this->sessionStore, $clock);
        $this->mfaStore = new InMemoryMfaStore();
        $this->signIn = new SignIn(
            $this->store,
            $this->hasher,
            $rehash,
            $this->limiter,
            $this->throttle,
            $sessions,
            $this->logger,
            new ChallengeGate($this->human),
            new MfaSettings($this->mfaStore, $this->store, $this->hasher, new ScriptedRateLimiter(), $clock, MfaFixtures::crypto()),
            new CompleteMfaSignIn(new InMemoryMfaChallengeStore(), $this->mfaStore, $this->store, $sessions, $clock, $this->logger, MfaFixtures::crypto()),
        );
        $this->addIdentity('active');
    }

    public function rehashWanted(): bool
    {
        return $this->needsRehash;
    }

    public function testCorrectPasswordIssuesASessionForTheIdentityAndItsAccount(): void
    {
        $issued = $this->issued(self::form(), self::IP, 'Firefox', null);

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $issued->sessionId);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $issued->csrfToken);
        self::assertSame(['id-1', 'acct-1'], [$issued->session->identityId, $issued->session->accountId]);
        self::assertSame([hash('sha256', $issued->sessionId)], array_keys($this->sessionStore->sessions), 'Only the hash of the ID is a key (T-20)');
        self::assertSame(Sessions::IDLE_SECONDS, $this->sessionStore->ttls[hash('sha256', $issued->sessionId)]);
    }

    public function testEmailIsMatchedCaseInsensitively(): void
    {
        $issued = $this->issued(self::form('ADA@Example.NG'), self::IP, '', null);

        self::assertSame('id-1', $issued->session->identityId);
    }

    public function testWrongPasswordAndUnknownEmailFailTheSameWayAndBothVerifyOneHash(): void
    {
        $outcomes = [];
        foreach ([self::form(password: 'not the password'), self::form('nobody@example.ng')] as $form) {
            try {
                $this->issued($form, self::IP, '', null);
                self::fail('Expected sign-in to fail.');
            } catch (InvalidCredentials $e) {
                $outcomes[] = [$e::class, $e->getMessage()];
            }
        }

        self::assertSame($outcomes[0], $outcomes[1], 'T-09: one outcome for both');
        self::assertSame([$this->store->identities['id-1']['password_hash'], SignIn::DUMMY_HASH], $this->hasher->verified, 'D-7: unknown email still costs one verification');
        self::assertSame([], $this->sessionStore->sessions);
    }

    public function testTheDummyHashUsesTheProductionArgon2idParameters(): void
    {
        self::assertFalse((new Argon2idPasswordHasher())->needsRehash(SignIn::DUMMY_HASH));
        self::assertSame('argon2id', password_get_info(SignIn::DUMMY_HASH)['algoName']);
    }

    public function testUnverifiedEmailIsRevealedOnlyAfterTheCorrectPassword(): void
    {
        $this->store->identities['id-1']['status'] = 'pending_verification';

        try {
            $this->issued(self::form(password: 'not the password'), self::IP, '', null);
            self::fail('Expected invalid credentials.');
        } catch (InvalidCredentials) {
        }

        $this->expectException(EmailNotVerified::class);
        $this->issued(self::form(), self::IP, '', null);
    }

    public function testSuspendedIdentityCannotSignIn(): void
    {
        $this->store->identities['id-1']['status'] = 'suspended';

        try {
            $this->issued(self::form(), self::IP, '', null);
            self::fail('Expected the account to be unavailable.');
        } catch (AccountUnavailable) {
        }
        self::assertSame([], $this->sessionStore->sessions);
    }

    public function testFifthFailureFromOneNetworkLocksThatPairOnly(): void
    {
        for ($i = 0; $i < 5; $i++) {
            try {
                $this->issued(self::form(password: 'wrong ' . $i), '198.51.100.' . (10 + $i), '', null);
            } catch (InvalidCredentials) {
            }
        }

        $verifies = count($this->hasher->verified);
        try {
            // Same /24, correct password: still refused, without hashing (T-11).
            $this->issued(self::form(), '198.51.100.200', '', null);
            self::fail('Expected the pair to be locked.');
        } catch (SignInThrottled $e) {
            self::assertSame(30, $e->retryAfterSeconds);
        }
        self::assertSame($verifies, count($this->hasher->verified));

        // T-08: the owner on another network is unaffected.
        $issued = $this->issued(self::form(), '203.0.113.9', '', null);
        self::assertSame('id-1', $issued->session->identityId);
    }

    public function testSuccessClearsThePairsFailures(): void
    {
        for ($i = 0; $i < 4; $i++) {
            try {
                $this->issued(self::form(password: 'wrong'), self::IP, '', null);
            } catch (InvalidCredentials) {
            }
        }
        $this->issued(self::form(), self::IP, '', null);

        self::assertSame([], $this->throttle->failures);
    }

    public function testPerIpLimitRunsBeforeAnyLookupOrHash(): void
    {
        $this->limiter->hits['signin:ip:' . hash('sha256', self::IP)] = SignIn::PER_IP_LIMIT;

        try {
            $this->issued(self::form(), self::IP, '', null);
            self::fail('Expected the IP limit to apply.');
        } catch (SignInThrottled $e) {
            self::assertSame(SignIn::PER_IP_WINDOW_SECONDS, $e->retryAfterSeconds);
        }
        self::assertSame([], $this->hasher->verified);
    }

    public function testManyFailuresAgainstOneAccountAreLoggedWithoutPersonalData(): void
    {
        for ($i = 0; $i <= SignIn::ACCOUNT_ALERT_THRESHOLD; $i++) {
            try {
                $this->issued(self::form(password: 'wrong'), '192.0.2.' . $i, '', null);
            } catch (InvalidCredentials) {
            }
            $this->throttle->unlock();
        }

        self::assertSame([['level' => 'warning', 'message' => 'auth.sign_in.account_failures_high', 'context' => ['identity_id' => 'id-1']]], $this->logger->records);
    }

    public function testNoChallengeIsAskedForBeforeTheThirdFailure(): void
    {
        $this->human->result = HumanCheck::Failed;
        for ($i = 0; $i < 2; $i++) {
            try {
                $this->issued(self::form(password: 'wrong'), self::IP, '', null);
            } catch (InvalidCredentials) {
            }
        }
        $this->issued(self::form(), self::IP, '', null);

        self::assertSame([], $this->human->calls);
    }

    public function testFromTheThirdFailureAPairMustPassTheChallengeBeforeAnyHash(): void
    {
        for ($i = 0; $i < 3; $i++) {
            try {
                $this->issued(self::form(password: 'wrong'), self::IP, '', null);
            } catch (InvalidCredentials) {
            }
        }
        $this->human->result = HumanCheck::Failed;
        $verifies = count($this->hasher->verified);

        try {
            $this->issued(['turnstile_token' => 'tok'] + self::form(), self::IP, '', null);
            self::fail('Expected a challenge.');
        } catch (ChallengeRequired) {
        }
        self::assertSame($verifies, count($this->hasher->verified), 'T-11: no hash without a passed challenge');
        self::assertSame(['token' => 'tok', 'action' => 'sign_in', 'ip' => self::IP], end($this->human->calls));

        $this->human->result = HumanCheck::Passed;
        self::assertSame('id-1', $this->issued(['turnstile_token' => 'tok2'] + self::form(), self::IP, '', null)->session->identityId);
    }

    public function testAccountWideFailuresRequireAChallengeFromEveryNetwork(): void
    {
        for ($i = 0; $i <= SignIn::ACCOUNT_ALERT_THRESHOLD; $i++) {
            try {
                $this->issued(self::form(password: 'wrong'), '192.0.' . $i . '.1', '', null);
            } catch (InvalidCredentials) {
            }
        }
        $this->human->result = HumanCheck::Unavailable;

        $this->expectException(ChallengeUnavailable::class);
        $this->issued(self::form(), '203.0.113.9', '', null);
    }

    public function testWhetherAChallengeIsNeededDoesNotRevealIfTheAccountExists(): void
    {
        $outcomes = [];
        foreach (['ada@example.ng', 'nobody@example.ng'] as $email) {
            for ($i = 0; $i <= SignIn::ACCOUNT_ALERT_THRESHOLD; $i++) {
                try {
                    $this->issued(self::form($email, 'wrong'), '192.0.' . $i . '.1', '', null);
                } catch (InvalidCredentials) {
                }
            }
            $this->human->result = HumanCheck::Failed;
            try {
                $this->issued(self::form($email, 'wrong'), '203.0.113.9', '', null);
                $outcomes[] = 'no challenge';
            } catch (ChallengeRequired) {
                $outcomes[] = 'challenge';
            } catch (InvalidCredentials) {
                $outcomes[] = 'no challenge';
            }
            $this->human->result = HumanCheck::Passed;
        }

        self::assertSame(['challenge', 'challenge'], $outcomes, 'T-09: same for a known and an unknown address');
    }

    public function testOutdatedHashIsReplacedAfterSuccessOnly(): void
    {
        $this->needsRehash = true;
        try {
            $this->issued(self::form(password: 'wrong'), self::IP, '', null);
        } catch (InvalidCredentials) {
        }
        self::assertSame(0, $this->hasher->hashes);

        $this->issued(self::form(), self::IP, '', null);
        self::assertSame(1, $this->hasher->hashes);
        self::assertTrue($this->hasher->verify(self::PASSWORD, $this->store->identities['id-1']['password_hash']));
    }

    public function testSigningInDestroysThePreviousSessionAndIssuesANewId(): void
    {
        $first = $this->issued(self::form(), self::IP, '', null);
        $second = $this->issued(self::form(), self::IP, '', $first->sessionId);

        self::assertNotSame($first->sessionId, $second->sessionId);
        self::assertSame([hash('sha256', $second->sessionId)], array_keys($this->sessionStore->sessions), 'T-18');
    }

    public function testMalformedInputIsRejectedBeforeAnyWork(): void
    {
        try {
            $this->issued(['email' => 'ada@example.ng', 'password' => self::PASSWORD, 'role' => 'admin'], self::IP, '', null);
            self::fail('Expected invalid input.');
        } catch (InvalidSignIn $e) {
            self::assertSame(['role' => 'unknown_field'], $e->errors);
        }
        self::assertSame([], $this->limiter->hits);
    }

    /** @return array<string, string> */
    private static function form(string $email = 'ada@example.ng', string $password = self::PASSWORD): array
    {
        return ['email' => $email, 'password' => $password];
    }

    private function addIdentity(string $status): void
    {
        $this->store->identities['id-1'] = [
            'id' => 'id-1',
            'email' => 'ada@example.ng',
            'lookup' => (new EmailAddress('ada@example.ng'))->hash(),
            'full_name' => 'Ada Okafor',
            'phone' => '+2348012345678',
            'password_hash' => $this->hasher->hash(self::PASSWORD),
            'status' => $status,
            'terms' => '2026-10-01',
            'verified_at' => null,
            'account_id' => 'acct-1',
        ];
        $this->hasher->hashes = 0;
    }

    public function testWithTotpOnACorrectPasswordGivesATicketAndNoSession(): void
    {
        $this->mfaStore->totp['id-1'] = ['secret_envelope' => 'v1.x.y', 'confirmed' => true, 'last_used_step' => null];

        $result = $this->signIn->signIn(self::form(), self::IP, '', null);

        self::assertInstanceOf(MfaPending::class, $result);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $result->ticket);
        self::assertSame([], $this->sessionStore->sessions, 'T-18: nothing is signed in before the second step');
    }

    public function testAnUnconfirmedTotpSetupDoesNotChangeSignIn(): void
    {
        $this->mfaStore->totp['id-1'] = ['secret_envelope' => 'v1.x.y', 'confirmed' => false, 'last_used_step' => null];

        self::assertSame('id-1', $this->issued(self::form(), self::IP, '', null)->session->identityId);
    }

    /** @param array<array-key, mixed> $form */
    private function issued(array $form, string $ip, string $userAgent, ?string $previous): IssuedSession
    {
        $result = $this->signIn->signIn($form, $ip, $userAgent, $previous);
        self::assertInstanceOf(IssuedSession::class, $result);

        return $result;
    }
}
