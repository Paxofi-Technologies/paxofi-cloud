<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Application\Identity\Mfa;

use PaxofiCloud\Application\Email\EmailAddress;
use PaxofiCloud\Application\Identity\Mfa\InvalidMfaInput;
use PaxofiCloud\Application\Identity\Mfa\MfaAlreadyEnabled;
use PaxofiCloud\Application\Identity\Mfa\MfaCrypto;
use PaxofiCloud\Application\Identity\Mfa\MfaNotEnabled;
use PaxofiCloud\Application\Identity\Mfa\MfaSettings;
use PaxofiCloud\Application\Identity\Mfa\MfaThrottled;
use PaxofiCloud\Application\Identity\Mfa\MfaUnavailable;
use PaxofiCloud\Application\Identity\Mfa\Totp;
use PaxofiCloud\Tests\Support\CountingPasswordHasher;
use PaxofiCloud\Tests\Support\InMemoryIdentityStore;
use PaxofiCloud\Tests\Support\InMemoryMfaStore;
use PaxofiCloud\Tests\Support\MfaFixtures;
use PaxofiCloud\Tests\Support\MutableClock;
use PaxofiCloud\Tests\Support\ScriptedRateLimiter;
use PHPUnit\Framework\TestCase;

/** SRS IAM-007, IAM-013, IAM-014; threat model D-4, D-5, T-13, T-15, T-17, §6.13. */
final class MfaSettingsTest extends TestCase
{
    private const string PASSWORD = 'Sup3r secret passphrase';

    private InMemoryMfaStore $mfa;
    private InMemoryIdentityStore $identities;
    private ScriptedRateLimiter $limiter;
    private MutableClock $clock;
    private MfaSettings $settings;

    protected function setUp(): void
    {
        $this->mfa = new InMemoryMfaStore();
        $this->identities = new InMemoryIdentityStore();
        $this->limiter = new ScriptedRateLimiter();
        $this->clock = new MutableClock(new \DateTimeImmutable('2026-10-26 09:00:00 UTC'));
        $hasher = new CountingPasswordHasher();
        $this->identities->identities['id-1'] = [
            'id' => 'id-1', 'email' => 'ada@example.ng', 'lookup' => (new EmailAddress('ada@example.ng'))->hash(), 'full_name' => 'Ada Okafor',
            'phone' => '+2348012345678', 'password_hash' => $hasher->hash(self::PASSWORD), 'status' => 'active', 'terms' => '2026-10-01',
            'verified_at' => null, 'account_id' => 'acct-1',
        ];
        $this->settings = new MfaSettings($this->mfa, $this->identities, $hasher, $this->limiter, $this->clock, MfaFixtures::crypto());
    }

    public function testSetupConfirmAndStatusRoundTrip(): void
    {
        self::assertSame(['totp_enabled' => false, 'recovery_codes_left' => 0], $this->settings->status('id-1'));

        $setup = $this->settings->setup('id-1', 'ada@example.ng', ['password' => self::PASSWORD]);
        self::assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $setup->secretBase32);
        self::assertStringContainsString('secret=' . $setup->secretBase32, $setup->uri);
        self::assertFalse($this->settings->isEnabled('id-1'), 'Not on until confirmed');

        $codes = $this->settings->confirm('id-1', ['code' => $this->currentCode()]);

        self::assertCount(10, $codes);
        self::assertSame(['totp_enabled' => true, 'recovery_codes_left' => 10], $this->settings->status('id-1'));
    }

    public function testTheSecretIsStoredEncryptedAndBoundToTheIdentity(): void
    {
        $setup = $this->settings->setup('id-1', 'ada@example.ng', ['password' => self::PASSWORD]);
        $envelope = $this->mfa->totp['id-1']['secret_envelope'];

        self::assertStringStartsWith('v1.t1.', $envelope);
        self::assertStringNotContainsString($setup->secretBase32, $envelope, 'T-15');
        $this->expectException(\RuntimeException::class);
        MfaFixtures::crypto()->secrets->decrypt($envelope, MfaCrypto::associatedData('id-2'));
    }

    public function testRecoveryCodesAreStoredOnlyAsKeyedHashes(): void
    {
        $this->settings->setup('id-1', 'ada@example.ng', ['password' => self::PASSWORD]);
        $codes = $this->settings->confirm('id-1', ['code' => $this->currentCode()]);

        foreach (array_keys($this->mfa->codes) as $stored) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $stored);
        }
        $all = json_encode($this->mfa->codes, JSON_THROW_ON_ERROR);
        foreach ($codes as $code) {
            self::assertStringNotContainsString(str_replace('-', '', $code), $all);
        }
    }

    public function testEveryFactorChangeNeedsTheCurrentPassword(): void
    {
        foreach (['setup', 'regenerate', 'disable'] as $action) {
            try {
                match ($action) {
                    'setup' => $this->settings->setup('id-1', 'ada@example.ng', ['password' => 'wrong password']),
                    'regenerate' => $this->settings->regenerateRecoveryCodes('id-1', ['password' => 'wrong password']),
                    'disable' => $this->settings->disable('id-1', ['password' => 'wrong password', 'code' => '123456']),
                };
                self::fail($action . ' accepted a wrong password');
            } catch (InvalidMfaInput $e) {
                self::assertSame(['password' => 'incorrect'], $e->errors, $action);
            }
        }
        self::assertSame([], $this->mfa->totp, 'T-17');
    }

    public function testWrongPasswordsAreThrottled(): void
    {
        $this->limiter->hits['mfa:password:id-1'] = MfaSettings::PASSWORD_LIMIT;

        $this->expectException(MfaThrottled::class);
        $this->settings->setup('id-1', 'ada@example.ng', ['password' => self::PASSWORD]);
    }

    public function testAWrongCodeDoesNotConfirmAndConfirmIsThrottled(): void
    {
        $this->settings->setup('id-1', 'ada@example.ng', ['password' => self::PASSWORD]);
        try {
            $this->settings->confirm('id-1', ['code' => '000000']);
            self::fail('Wrong code accepted');
        } catch (InvalidMfaInput $e) {
            self::assertSame(['code' => 'incorrect'], $e->errors);
        }
        self::assertFalse($this->settings->isEnabled('id-1'));

        $this->limiter->hits['mfa:confirm:id-1'] = MfaSettings::CODE_LIMIT;
        $this->expectException(MfaThrottled::class);
        $this->settings->confirm('id-1', ['code' => $this->currentCode()]);
    }

    public function testANewSetupReplacesAnUnconfirmedOneButNotAConfirmedOne(): void
    {
        $first = $this->settings->setup('id-1', 'ada@example.ng', ['password' => self::PASSWORD]);
        $second = $this->settings->setup('id-1', 'ada@example.ng', ['password' => self::PASSWORD]);
        self::assertNotSame($first->secretBase32, $second->secretBase32);
        $stored = MfaFixtures::crypto()->secrets->decrypt($this->mfa->totp['id-1']['secret_envelope'], MfaCrypto::associatedData('id-1'));
        self::assertSame(self::secretFrom($second->secretBase32), $stored, 'Only the newest unconfirmed secret is kept');

        $this->settings->confirm('id-1', ['code' => $this->currentCode()]);
        $this->expectException(MfaAlreadyEnabled::class);
        $this->settings->setup('id-1', 'ada@example.ng', ['password' => self::PASSWORD]);
    }

    public function testDisableNeedsPasswordAndAWorkingSecondFactor(): void
    {
        $this->settings->setup('id-1', 'ada@example.ng', ['password' => self::PASSWORD]);
        $codes = $this->settings->confirm('id-1', ['code' => $this->currentCode()]);

        try {
            $this->settings->disable('id-1', ['password' => self::PASSWORD, 'recovery_code' => 'AAAA-AAAA-AAAA-AAAA']);
            self::fail('Unknown recovery code accepted');
        } catch (InvalidMfaInput $e) {
            self::assertSame(['recovery_code' => 'incorrect'], $e->errors);
        }
        self::assertTrue($this->settings->isEnabled('id-1'));

        $this->settings->disable('id-1', ['password' => self::PASSWORD, 'recovery_code' => strtolower($codes[3])]);
        self::assertFalse($this->settings->isEnabled('id-1'));
        self::assertSame([], $this->mfa->codes);
    }

    public function testTheCodeThatConfirmedCannotBeReusedToDisable(): void
    {
        $this->settings->setup('id-1', 'ada@example.ng', ['password' => self::PASSWORD]);
        $code = $this->currentCode();
        $this->settings->confirm('id-1', ['code' => $code]);

        $this->expectException(InvalidMfaInput::class);
        $this->settings->disable('id-1', ['password' => self::PASSWORD, 'code' => $code]);
    }

    public function testRegeneratingRecoveryCodesKillsTheOldSet(): void
    {
        $this->settings->setup('id-1', 'ada@example.ng', ['password' => self::PASSWORD]);
        $old = $this->settings->confirm('id-1', ['code' => $this->currentCode()]);
        $new = $this->settings->regenerateRecoveryCodes('id-1', ['password' => self::PASSWORD]);

        self::assertSame([], array_intersect($old, $new));
        $this->expectException(InvalidMfaInput::class);
        $this->settings->disable('id-1', ['password' => self::PASSWORD, 'recovery_code' => $old[0]]);
    }

    public function testActionsThatNeedTotpOnAreRefusedWhenItIsOff(): void
    {
        $this->expectException(MfaNotEnabled::class);
        $this->settings->regenerateRecoveryCodes('id-1', ['password' => self::PASSWORD]);
    }

    public function testStrictInput(): void
    {
        try {
            $this->settings->setup('id-1', 'ada@example.ng', ['password' => self::PASSWORD, 'secret' => 'MINE']);
            self::fail('Extra field accepted');
        } catch (InvalidMfaInput $e) {
            self::assertSame(['secret' => 'unknown_field'], $e->errors);
        }
        self::assertSame([], $this->mfa->totp, 'A client never chooses its own secret');
    }

    public function testWithoutKeysMfaIsUnavailableNotSkipped(): void
    {
        $settings = new MfaSettings($this->mfa, $this->identities, new CountingPasswordHasher(), $this->limiter, $this->clock, null);

        $this->expectException(MfaUnavailable::class);
        $settings->setup('id-1', 'ada@example.ng', ['password' => self::PASSWORD]);
    }

    private function currentCode(): string
    {
        $setup = $this->mfa->totp['id-1'] ?? null;
        self::assertNotNull($setup);
        $secret = MfaFixtures::crypto()->secrets->decrypt($setup['secret_envelope'], MfaCrypto::associatedData('id-1'));

        return Totp::code($secret, Totp::step($this->clock->now()));
    }

    private static function secretFrom(string $base32): string
    {
        $bits = '';
        foreach (str_split($base32) as $char) {
            $bits .= str_pad(decbin((int) strpos('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567', $char)), 5, '0', STR_PAD_LEFT);
        }

        return implode('', array_map(static fn (string $byte): string => chr((int) bindec($byte)), str_split(substr($bits, 0, intdiv(strlen($bits), 8) * 8), 8)));
    }
}
