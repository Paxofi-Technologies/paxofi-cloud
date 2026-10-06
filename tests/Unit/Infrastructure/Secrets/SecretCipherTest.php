<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Infrastructure\Secrets;

use PaxofiCloud\Infrastructure\Secrets\DecryptionFailed;
use PaxofiCloud\Infrastructure\Secrets\KeyRing;
use PaxofiCloud\Infrastructure\Secrets\SecretCipher;
use PaxofiCloud\Infrastructure\Secrets\StoredCredential;
use PHPUnit\Framework\TestCase;

/** SRS SEC-004; threat model V-01 (ProviderCredentialEncryptionTest, CredentialAadBindingTest, CredentialRotationTest). */
final class SecretCipherTest extends TestCase
{
    private const string SECRET = 'test-token-not-real-0123456789abcdef';

    public function testRoundTripsAndNeverStoresPlaintext(): void
    {
        $cipher = new SecretCipher(self::ring('k1'));
        $envelope = $cipher->encrypt(self::SECRET, 'aad');

        self::assertStringStartsWith('v1.k1.', $envelope);
        self::assertStringNotContainsString(self::SECRET, $envelope);
        self::assertSame(self::SECRET, $cipher->decrypt($envelope, 'aad'));
    }

    public function testEachEncryptionUsesAFreshNonce(): void
    {
        $cipher = new SecretCipher(self::ring('k1'));

        self::assertNotSame($cipher->encrypt(self::SECRET, 'aad'), $cipher->encrypt(self::SECRET, 'aad'));
    }

    public function testCiphertextCopiedToAnotherRecordDoesNotDecrypt(): void
    {
        $cipher = new SecretCipher(self::ring('k1'));
        $readOnly = new StoredCredential('hetzner-prod-reconcile', 'hetzner', 'prod', 'read-only');
        $readWrite = new StoredCredential('hetzner-prod-reconcile', 'hetzner', 'prod', 'read-write');

        $envelope = $cipher->encrypt(self::SECRET, $readOnly->associatedData());

        $this->expectException(DecryptionFailed::class);
        $cipher->decrypt($envelope, $readWrite->associatedData());
    }

    public function testTamperedCiphertextIsRejected(): void
    {
        $cipher = new SecretCipher(self::ring('k1'));
        $envelope = $cipher->encrypt(self::SECRET, 'aad');
        [$v, $k, $payload] = explode('.', $envelope);
        $bytes = sodium_base642bin($payload, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        $bytes[30] = chr(ord($bytes[30]) ^ 0x01);

        $this->expectException(DecryptionFailed::class);
        $cipher->decrypt(implode('.', [$v, $k, sodium_bin2base64($bytes, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING)]), 'aad');
    }

    public function testSwappingTheKeyIdIsRejected(): void
    {
        $ring = KeyRing::fromEnvironment(implode(',', [KeyRing::generateEntry('k1'), KeyRing::generateEntry('k2')]), 'k1');
        $envelope = (new SecretCipher($ring))->encrypt(self::SECRET, 'aad');

        $this->expectException(DecryptionFailed::class);
        (new SecretCipher($ring))->decrypt(str_replace('v1.k1.', 'v1.k2.', $envelope), 'aad');
    }

    /** @return iterable<string, array{string}> */
    public static function malformed(): iterable
    {
        yield 'empty' => [''];
        yield 'plaintext stored by mistake' => [self::SECRET];
        yield 'unknown version' => ['v2.k1.AAAA'];
        yield 'bad key id' => ['v1.K!.AAAA'];
        yield 'bad base64' => ['v1.k1.***'];
        yield 'too short' => ['v1.k1.AAAA'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformed')]
    public function testMalformedEnvelopesAreRejected(string $envelope): void
    {
        $this->expectException(DecryptionFailed::class);

        (new SecretCipher(self::ring('k1')))->decrypt($envelope, 'aad');
    }

    public function testUnknownKeyIdIsRejected(): void
    {
        $envelope = (new SecretCipher(self::ring('old')))->encrypt(self::SECRET, 'aad');

        $this->expectException(DecryptionFailed::class);
        $this->expectExceptionMessage('Unknown key ID "old"');
        (new SecretCipher(self::ring('new')))->decrypt($envelope, 'aad');
    }

    public function testRotationOldCiphertextsStayReadableAndAreFlaggedForReencryption(): void
    {
        $old = KeyRing::generateEntry('k2025');
        $new = KeyRing::generateEntry('k2026');
        $before = new SecretCipher(KeyRing::fromEnvironment($old, 'k2025'));
        $envelope = $before->encrypt(self::SECRET, 'aad');

        $after = new SecretCipher(KeyRing::fromEnvironment($new . ',' . $old, 'k2026'));

        self::assertTrue($after->needsReencryption($envelope));
        self::assertSame(self::SECRET, $after->decrypt($envelope, 'aad'));
        $rewrapped = $after->encrypt($after->decrypt($envelope, 'aad'), 'aad');
        self::assertFalse($after->needsReencryption($rewrapped));
        self::assertSame('k2026', SecretCipher::keyIdOf($rewrapped));
    }

    public function testRefusesToEncryptAnEmptySecret(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new SecretCipher(self::ring('k1')))->encrypt('', 'aad');
    }

    private static function ring(string $id): KeyRing
    {
        return KeyRing::fromEnvironment(KeyRing::generateEntry($id), $id);
    }
}
