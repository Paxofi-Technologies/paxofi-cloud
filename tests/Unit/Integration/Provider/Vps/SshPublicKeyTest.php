<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Integration\Provider\Vps;

use PaxofiCloud\Integration\Provider\Vps\InvalidSshKey;
use PaxofiCloud\Integration\Provider\Vps\SshPublicKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** SRS VPS-002, threat model V-11 / D-9. */
final class SshPublicKeyTest extends TestCase
{
    public function testAcceptsEd25519AndDropsTheComment(): void
    {
        $key = SshPublicKey::parse(self::fixture('ed25519'));

        self::assertSame('ssh-ed25519', $key->type);
        self::assertSame(256, $key->bits);
        self::assertStringNotContainsString('test@example', $key->openSsh());
        self::assertSame(explode(' ', self::fixture('ed25519'))[1], explode(' ', $key->openSsh())[1]);
    }

    public function testFingerprintMatchesOpenSshFormat(): void
    {
        // Reference value computed independently: base64(sha256(blob)) without padding.
        self::assertSame('SHA256:2BJPairl1soxTAjGDc8Hg6QBi+v3cX9A7K4GiR23NfQ', SshPublicKey::parse(self::fixture('ed25519'))->fingerprint());
    }

    public function testAcceptsRsa3072(): void
    {
        $key = SshPublicKey::parse(self::fixture('rsa3072'));

        self::assertSame('ssh-rsa', $key->type);
        self::assertSame(3072, $key->bits);
    }

    public function testRejectsRsaBelow3072Bits(): void
    {
        $this->expectException(InvalidSshKey::class);
        $this->expectExceptionMessage('at least 3072 bits; this key has 2048');

        SshPublicKey::parse(self::fixture('rsa2048'));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidKeys(): iterable
    {
        $ed = explode(' ', self::fixture('ed25519'))[1];
        $blob = (string) base64_decode($ed, true);

        yield 'empty' => [''];
        yield 'type only' => ['ssh-ed25519'];
        yield 'not base64' => ['ssh-ed25519 !!!not-base64!!!'];
        yield 'declared type differs from contents' => ['ssh-rsa ' . $ed];
        yield 'unsupported type (dss)' => ['ssh-dss ' . base64_encode(pack('N', 7) . 'ssh-dss' . pack('N', 1) . 'x')];
        yield 'truncated blob' => ['ssh-ed25519 ' . base64_encode(substr($blob, 0, 20))];
        yield 'trailing data' => ['ssh-ed25519 ' . base64_encode($blob . 'extra')];
        yield 'multi-line injection' => ['ssh-ed25519 ' . $ed . "\nssh-ed25519 " . $ed];
        yield 'private key pasted' => ['-----BEGIN OPENSSH PRIVATE KEY-----'];
    }

    #[DataProvider('invalidKeys')]
    public function testRejectsInvalidKeys(string $line): void
    {
        $this->expectException(InvalidSshKey::class);

        SshPublicKey::parse($line);
    }

    private static function fixture(string $name): string
    {
        return trim((string) file_get_contents(__DIR__ . '/../../../../Fixtures/ssh/' . $name . '.pub'));
    }
}
