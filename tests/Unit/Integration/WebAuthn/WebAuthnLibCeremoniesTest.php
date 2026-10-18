<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Integration\WebAuthn;

use PaxofiCloud\Application\Staff\Passkeys\PasskeyRejected;
use PaxofiCloud\Application\Staff\Passkeys\StoredPasskey;
use PaxofiCloud\Application\Staff\Passkeys\VerifiedPasskey;
use PaxofiCloud\Integration\WebAuthn\WebAuthnLibCeremonies;
use PaxofiCloud\Tests\Support\SoftwareAuthenticator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Real WebAuthn ceremonies through web-auth/webauthn-lib with a software
 * authenticator (ADR-002; threat model D-10, T-29, T-30, §6.17).
 */
final class WebAuthnLibCeremoniesTest extends TestCase
{
    private const string ORIGIN = 'https://admin.example.test';
    private const string NAME = 'ada@example.test';

    private WebAuthnLibCeremonies $ceremonies;
    private string $userHandle;

    protected function setUp(): void
    {
        $this->ceremonies = new WebAuthnLibCeremonies(self::ORIGIN);
        $this->userHandle = random_bytes(32);
    }

    /** @return iterable<string, array{int}> */
    public static function algorithms(): iterable
    {
        yield 'ES256' => [-7];
        yield 'EdDSA' => [-8];
    }

    #[DataProvider('algorithms')]
    public function testRegistersAndSignsInWithEachAllowedAlgorithm(int $algorithm): void
    {
        $authenticator = new SoftwareAuthenticator(self::ORIGIN, $algorithm);
        $passkey = $this->register($authenticator);

        self::assertSame($algorithm, $passkey->algorithm);
        self::assertSame($authenticator->credentialId, $passkey->credentialId);

        [$json, $challenge] = $this->assertWith($authenticator);
        self::assertSame($authenticator->credentialId, $this->ceremonies->assertionCredentialId($json));
        self::assertSame(1, $this->ceremonies->verifyAssertion($json, $challenge, $this->stored($passkey))->signCount);
    }

    public function testOptionsFollowThePolicy(): void
    {
        $options = $this->ceremonies->registrationOptions(random_bytes(32), $this->userHandle, self::NAME, 'Ada', [random_bytes(16)]);

        self::assertSame(['id' => 'admin.example.test', 'name' => 'PaxofiCloud Admin'], $options['rp']);
        self::assertSame([['type' => 'public-key', 'alg' => -7], ['type' => 'public-key', 'alg' => -8]], $options['pubKeyCredParams']);
        self::assertSame('none', $options['attestation']);
        $selection = $options['authenticatorSelection'];
        self::assertIsArray($selection);
        self::assertSame('required', $selection['userVerification']);
        self::assertSame('required', $selection['residentKey']);
        self::assertIsArray($options['excludeCredentials']);
        self::assertCount(1, $options['excludeCredentials']);

        $request = $this->ceremonies->requestOptions(random_bytes(32));
        self::assertSame('admin.example.test', $request['rpId']);
        self::assertSame('required', $request['userVerification']);
        self::assertSame([], $request['allowCredentials'], 'usernameless: no staff credentials are disclosed');
    }

    public function testRegistrationFromAnotherOriginIsRejected(): void
    {
        $authenticator = new SoftwareAuthenticator('https://admin.example.test.evil.example');
        $this->expectRejected(PasskeyRejected::INVALID, fn () => $this->register($authenticator));
    }

    public function testRegistrationWithoutUserVerificationIsRejected(): void
    {
        $authenticator = new SoftwareAuthenticator(self::ORIGIN);
        $authenticator->userVerified = false;
        $this->expectRejected(PasskeyRejected::INVALID, fn () => $this->register($authenticator));
    }

    public function testRegistrationAgainstAnotherChallengeIsRejected(): void
    {
        $authenticator = new SoftwareAuthenticator(self::ORIGIN);
        $options = $this->ceremonies->registrationOptions(random_bytes(32), $this->userHandle, self::NAME, 'Ada', []);

        $this->expectRejected(PasskeyRejected::INVALID, fn () => $this->ceremonies->verifyRegistration(
            $authenticator->register($options), random_bytes(32), $this->userHandle, self::NAME, 'Ada', [],
        ));
    }

    public function testAnAlreadyRegisteredCredentialIsRejected(): void
    {
        $authenticator = new SoftwareAuthenticator(self::ORIGIN);
        $challenge = random_bytes(32);
        $exclude = [$authenticator->credentialId];
        $options = $this->ceremonies->registrationOptions($challenge, $this->userHandle, self::NAME, 'Ada', $exclude);

        $this->expectRejected(PasskeyRejected::INVALID, fn () => $this->ceremonies->verifyRegistration(
            $authenticator->register($options), $challenge, $this->userHandle, self::NAME, 'Ada', $exclude,
        ));
    }

    public function testAssertionWithoutUserVerificationIsRejected(): void
    {
        $authenticator = new SoftwareAuthenticator(self::ORIGIN);
        $passkey = $this->register($authenticator);
        $authenticator->userVerified = false;

        [$json, $challenge] = $this->assertWith($authenticator);
        $this->expectRejected(PasskeyRejected::INVALID, fn () => $this->ceremonies->verifyAssertion($json, $challenge, $this->stored($passkey)));
    }

    public function testAssertionFromAnotherOriginOrChallengeIsRejected(): void
    {
        $authenticator = new SoftwareAuthenticator(self::ORIGIN);
        $passkey = $this->register($authenticator);

        [$json] = $this->assertWith($authenticator);
        $this->expectRejected(PasskeyRejected::INVALID, fn () => $this->ceremonies->verifyAssertion($json, random_bytes(32), $this->stored($passkey)));

        $authenticator->origin = 'https://phish.example';
        [$json, $challenge] = $this->assertWith($authenticator);
        $this->expectRejected(PasskeyRejected::INVALID, fn () => $this->ceremonies->verifyAssertion($json, $challenge, $this->stored($passkey)));
    }

    public function testAssertionSignedByAnotherKeyIsRejected(): void
    {
        $owner = new SoftwareAuthenticator(self::ORIGIN);
        $passkey = $this->register($owner);
        $attacker = new SoftwareAuthenticator(self::ORIGIN);
        $attacker->claimCredentialId = $owner->credentialId;

        [$json, $challenge] = $this->assertWith($attacker);
        $this->expectRejected(PasskeyRejected::INVALID, fn () => $this->ceremonies->verifyAssertion($json, $challenge, $this->stored($passkey)));
    }

    public function testAssertionForAnotherUserHandleIsRejected(): void
    {
        $authenticator = new SoftwareAuthenticator(self::ORIGIN);
        $passkey = $this->register($authenticator);

        $challenge = random_bytes(32);
        $json = $authenticator->assert($this->ceremonies->requestOptions($challenge), random_bytes(32));
        $this->expectRejected(PasskeyRejected::INVALID, fn () => $this->ceremonies->verifyAssertion($json, $challenge, $this->stored($passkey)));
    }

    public function testAssertionForADifferentStoredCredentialIsMalformed(): void
    {
        $authenticator = new SoftwareAuthenticator(self::ORIGIN);
        $passkey = $this->register($authenticator);
        $other = new SoftwareAuthenticator(self::ORIGIN);
        $this->register($other);

        [$json, $challenge] = $this->assertWith($other);
        $this->expectRejected(PasskeyRejected::MALFORMED, fn () => $this->ceremonies->verifyAssertion($json, $challenge, $this->stored($passkey)));
    }

    /** T-30: a sign counter that does not increase means a possible cloned authenticator. */
    public function testCounterRegressionIsReportedAsSuch(): void
    {
        $authenticator = new SoftwareAuthenticator(self::ORIGIN, -7, 10);
        $passkey = $this->register($authenticator);
        $stored = new StoredPasskey('p', 's', $passkey->credentialId, $passkey->publicKey, $passkey->algorithm, 50, $passkey->aaguid, '', false, false, $this->userHandle);

        [$json, $challenge] = $this->assertWith($authenticator);
        $this->expectRejected(PasskeyRejected::COUNTER, fn () => $this->ceremonies->verifyAssertion($json, $challenge, $stored));
    }

    public function testMalformedResponsesAreRejectedBeforeVerification(): void
    {
        foreach (['', '{', '{"id":"x"}', str_repeat('a', 70_000), '[]'] as $json) {
            $this->expectRejected(PasskeyRejected::MALFORMED, fn () => $this->ceremonies->assertionCredentialId($json));
        }

        // A registration response is not an assertion, and the reverse.
        $authenticator = new SoftwareAuthenticator(self::ORIGIN);
        $options = $this->ceremonies->registrationOptions(random_bytes(32), $this->userHandle, self::NAME, 'Ada', []);
        $this->expectRejected(PasskeyRejected::MALFORMED, fn () => $this->ceremonies->assertionCredentialId($authenticator->register($options)));
        [$json, $challenge] = $this->assertWith($authenticator);
        $this->expectRejected(PasskeyRejected::MALFORMED, fn () => $this->ceremonies->verifyRegistration($json, $challenge, $this->userHandle, self::NAME, 'Ada', []));

        $authenticator->type = 'password';
        [$json] = $this->assertWith($authenticator);
        $this->expectRejected(PasskeyRejected::MALFORMED, fn () => $this->ceremonies->assertionCredentialId($json));
    }

    /** @return iterable<string, array{string}> */
    public static function badAdminUrls(): iterable
    {
        yield 'http' => ['http://admin.example.test'];
        yield 'path' => ['https://admin.example.test/admin'];
        yield 'query' => ['https://admin.example.test/?x=1'];
        yield 'userinfo' => ['https://u:p@admin.example.test'];
        yield 'empty' => [''];
    }

    #[DataProvider('badAdminUrls')]
    public function testAdminUrlMustBeAnHttpsOrigin(string $url): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new WebAuthnLibCeremonies($url);
    }

    private function register(SoftwareAuthenticator $authenticator): VerifiedPasskey
    {
        $challenge = random_bytes(32);
        $options = $this->ceremonies->registrationOptions($challenge, $this->userHandle, self::NAME, 'Ada', []);

        return $this->ceremonies->verifyRegistration($authenticator->register($options), $challenge, $this->userHandle, self::NAME, 'Ada', []);
    }

    /** @return array{string, string} */
    private function assertWith(SoftwareAuthenticator $authenticator): array
    {
        $challenge = random_bytes(32);

        return [$authenticator->assert($this->ceremonies->requestOptions($challenge), $this->userHandle), $challenge];
    }

    private function stored(VerifiedPasskey $passkey): StoredPasskey
    {
        return new StoredPasskey('p', 's', $passkey->credentialId, $passkey->publicKey, $passkey->algorithm, $passkey->signCount, $passkey->aaguid, implode(',', $passkey->transports), $passkey->backupEligible, $passkey->backupState, $this->userHandle);
    }

    /** @param callable(): mixed $action */
    private function expectRejected(string $reason, callable $action): void
    {
        try {
            $action();
            self::fail('Expected rejection: ' . $reason);
        } catch (PasskeyRejected $e) {
            self::assertSame($reason, $e->reason);
        }
    }
}
