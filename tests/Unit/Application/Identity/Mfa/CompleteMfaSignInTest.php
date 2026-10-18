<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Application\Identity\Mfa;

use PaxofiCloud\Application\Email\EmailAddress;
use PaxofiCloud\Application\Identity\Mfa\CompleteMfaSignIn;
use PaxofiCloud\Application\Identity\Mfa\InvalidMfaInput;
use PaxofiCloud\Application\Identity\Mfa\MfaCrypto;
use PaxofiCloud\Application\Identity\Mfa\MfaThrottled;
use PaxofiCloud\Application\Identity\Mfa\MfaTicketInvalid;
use PaxofiCloud\Application\Identity\Mfa\RecoveryCodes;
use PaxofiCloud\Application\Identity\Mfa\Totp;
use PaxofiCloud\Application\Identity\Session\Sessions;
use PaxofiCloud\Application\Identity\SignIn\AccountUnavailable;
use PaxofiCloud\Tests\Support\InMemoryIdentityStore;
use PaxofiCloud\Tests\Support\InMemoryMfaChallengeStore;
use PaxofiCloud\Tests\Support\InMemoryMfaStore;
use PaxofiCloud\Tests\Support\InMemorySessionStore;
use PaxofiCloud\Tests\Support\MfaFixtures;
use PaxofiCloud\Tests\Support\MutableClock;
use PaxofiCloud\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;

/** SRS IAM-007; threat model T-13, T-14, T-18, §6.13. */
final class CompleteMfaSignInTest extends TestCase
{
    private const string SECRET = '12345678901234567890';

    private InMemoryMfaChallengeStore $challenges;
    private InMemoryMfaStore $mfa;
    private InMemoryIdentityStore $identities;
    private InMemorySessionStore $sessions;
    private MutableClock $clock;
    private RecordingLogger $logger;
    private CompleteMfaSignIn $complete;
    /** @var list<string> */
    private array $recoveryCodes;

    protected function setUp(): void
    {
        $this->challenges = new InMemoryMfaChallengeStore();
        $this->mfa = new InMemoryMfaStore();
        $this->identities = new InMemoryIdentityStore();
        $this->sessions = new InMemorySessionStore();
        $this->clock = new MutableClock(new \DateTimeImmutable('2026-10-26 09:00:00 UTC'));
        $this->logger = new RecordingLogger();
        $crypto = MfaFixtures::crypto();
        $this->identities->identities['id-1'] = [
            'id' => 'id-1', 'email' => 'ada@example.ng', 'lookup' => (new EmailAddress('ada@example.ng'))->hash(), 'full_name' => 'Ada Okafor',
            'phone' => '+2348012345678', 'password_hash' => 'x', 'status' => 'active', 'terms' => '2026-10-01', 'verified_at' => null, 'account_id' => 'acct-1',
        ];
        $this->mfa->totp['id-1'] = ['secret_envelope' => $crypto->secrets->encrypt(self::SECRET, MfaCrypto::associatedData('id-1')), 'confirmed' => true, 'last_used_step' => null];
        $this->recoveryCodes = RecoveryCodes::generate();
        $this->mfa->replaceRecoveryCodes('id-1', array_map(static fn (string $c): string => (string) $crypto->recoveryCodes->hash($c), $this->recoveryCodes));
        $this->complete = new CompleteMfaSignIn($this->challenges, $this->mfa, $this->identities, new Sessions($this->sessions, $this->clock), $this->clock, $this->logger, $crypto);
    }

    public function testATicketAndTheCurrentCodeGiveASessionAndEndTheTicket(): void
    {
        $ticket = $this->complete->start('id-1', 'acct-1')->ticket;

        $issued = $this->complete->complete($ticket, ['code' => $this->code()], '198.51.100.7', 'UA');

        self::assertSame(['id-1', 'acct-1'], [$issued->session->identityId, $issued->session->accountId]);
        self::assertSame([], $this->challenges->tickets, 'Single use');
        $this->expectException(MfaTicketInvalid::class);
        $this->complete->complete($ticket, ['code' => $this->code()], '198.51.100.7', 'UA');
    }

    public function testOnlyTheTicketHashIsStored(): void
    {
        $ticket = $this->complete->start('id-1', 'acct-1')->ticket;

        self::assertSame([hash('sha256', $ticket)], array_keys($this->challenges->tickets));
    }

    public function testACodeWorksOnlyOnce(): void
    {
        $code = $this->code();
        $this->complete->complete($this->complete->start('id-1', 'acct-1')->ticket, ['code' => $code], '', '');

        $this->expectException(InvalidMfaInput::class);
        $this->complete->complete($this->complete->start('id-1', 'acct-1')->ticket, ['code' => $code], '', '');
    }

    public function testARecoveryCodeWorksOnceAndIsLogged(): void
    {
        $this->complete->complete($this->complete->start('id-1', 'acct-1')->ticket, ['recovery_code' => $this->recoveryCodes[0]], '', '');

        self::assertSame([['level' => 'info', 'message' => 'auth.mfa.recovery_code_used', 'context' => ['identity_id' => 'id-1']]], $this->logger->records);
        $this->expectException(InvalidMfaInput::class);
        $this->complete->complete($this->complete->start('id-1', 'acct-1')->ticket, ['recovery_code' => $this->recoveryCodes[0]], '', '');
    }

    public function testFiveWrongCodesEndTheTicketAndLockTheIdentity(): void
    {
        $ticket = $this->complete->start('id-1', 'acct-1')->ticket;
        for ($i = 0; $i < CompleteMfaSignIn::MAX_ATTEMPTS; $i++) {
            try {
                $this->complete->complete($ticket, ['code' => '000000'], '', '');
            } catch (InvalidMfaInput) {
            }
        }
        self::assertSame([], $this->challenges->tickets, 'T-14: ticket gone');
        self::assertSame('auth.mfa.locked', $this->logger->records[0]['message']);

        // A fresh ticket from a new password sign-in is still locked out, even with the right code.
        try {
            $this->complete->complete($this->complete->start('id-1', 'acct-1')->ticket, ['code' => $this->code()], '', '');
            self::fail('Locked identity signed in');
        } catch (MfaThrottled $e) {
            self::assertSame(CompleteMfaSignIn::LOCK_SECONDS, $e->retryAfterSeconds);
        }
        self::assertSame([], $this->sessions->sessions);
    }

    public function testMissingOrForgedTicketsAreRefusedBeforeAnyCheck(): void
    {
        foreach ([null, 'short', str_repeat('A', 43)] as $ticket) {
            try {
                $this->complete->complete($ticket, ['code' => $this->code()], '', '');
                self::fail('Accepted ticket ' . var_export($ticket, true));
            } catch (MfaTicketInvalid) {
            }
        }
        self::assertNull($this->mfa->totp['id-1']['last_used_step'], 'The code was not consumed');
    }

    public function testASuspendedIdentityGetsNoSessionEvenWithTheRightCode(): void
    {
        $ticket = $this->complete->start('id-1', 'acct-1')->ticket;
        $this->identities->identities['id-1']['status'] = 'suspended';

        $this->expectException(AccountUnavailable::class);
        $this->complete->complete($ticket, ['code' => $this->code()], '', '');
    }

    public function testInputMustBeExactlyOneFactor(): void
    {
        $ticket = $this->complete->start('id-1', 'acct-1')->ticket;
        try {
            $this->complete->complete($ticket, ['code' => $this->code(), 'recovery_code' => $this->recoveryCodes[0]], '', '');
            self::fail('Two factors accepted');
        } catch (InvalidMfaInput $e) {
            self::assertSame(['recovery_code' => 'unknown_field'], $e->errors);
        }
    }

    private function code(): string
    {
        return Totp::code(self::SECRET, Totp::step($this->clock->now()));
    }
}
