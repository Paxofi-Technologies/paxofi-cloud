<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Application\Identity\Mfa;

use PaxofiCloud\Application\Identity\Mfa\Base32;
use PaxofiCloud\Application\Identity\Mfa\Totp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Threat model D-4, T-13. */
final class TotpTest extends TestCase
{
    private const string RFC_SECRET = '12345678901234567890';

    /** RFC 6238 appendix B, SHA-1 column (8 digits). */
    #[DataProvider('rfc6238')]
    public function testRfc6238TestVectors(int $time, string $expected): void
    {
        self::assertSame($expected, Totp::code(self::RFC_SECRET, intdiv($time, 30), 8));
    }

    /** @return iterable<string, array{int, string}> */
    public static function rfc6238(): iterable
    {
        yield '59' => [59, '94287082'];
        yield '1111111109' => [1111111109, '07081804'];
        yield '1111111111' => [1111111111, '14050471'];
        yield '1234567890' => [1234567890, '89005924'];
        yield '2000000000' => [2000000000, '69279037'];
        yield '20000000000' => [20000000000, '65353130'];
    }

    public function testSixDigitCodesAreTheLastSixOfTheRfcValue(): void
    {
        self::assertSame('287082', Totp::code(self::RFC_SECRET, 1));
    }

    public function testCurrentAndAdjacentStepsMatchButNotFurther(): void
    {
        $now = new \DateTimeImmutable('@1111111111');
        $step = Totp::step($now);

        foreach ([-1, 0, 1] as $offset) {
            self::assertSame($step + $offset, Totp::match(self::RFC_SECRET, Totp::code(self::RFC_SECRET, $step + $offset), $now, null));
        }
        self::assertNull(Totp::match(self::RFC_SECRET, Totp::code(self::RFC_SECRET, $step + 2), $now, null));
        self::assertNull(Totp::match(self::RFC_SECRET, Totp::code(self::RFC_SECRET, $step - 2), $now, null));
    }

    public function testACodeForAnAlreadyUsedStepIsRefused(): void
    {
        $now = new \DateTimeImmutable('@1111111111');
        $step = Totp::step($now);
        $code = Totp::code(self::RFC_SECRET, $step);

        self::assertNull(Totp::match(self::RFC_SECRET, $code, $now, $step), 'T-13: replay');
        self::assertNull(Totp::match(self::RFC_SECRET, Totp::code(self::RFC_SECRET, $step - 1), $now, $step));
        self::assertSame($step + 1, Totp::match(self::RFC_SECRET, Totp::code(self::RFC_SECRET, $step + 1), $now, $step));
    }

    public function testMalformedCodesNeverMatch(): void
    {
        $now = new \DateTimeImmutable('@1111111111');
        foreach (['', '12345', '1234567', '12 456', 'abcdef', '050471 '] as $code) {
            self::assertNull(Totp::match(self::RFC_SECRET, $code, $now, null), $code);
        }
    }

    public function testSecretsAre160BitsAndTheUriIsWhatAuthenticatorsExpect(): void
    {
        $secret = Totp::newSecret();
        self::assertSame(20, strlen($secret));
        self::assertNotSame($secret, Totp::newSecret());

        self::assertSame(
            'otpauth://totp/PaxofiCloud:ada%40example.ng?secret=GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ&issuer=PaxofiCloud&algorithm=SHA1&digits=6&period=30',
            Totp::uri(self::RFC_SECRET, 'ada@example.ng'),
        );
    }

    public function testBase32MatchesRfc4648(): void
    {
        self::assertSame('', Base32::encode(''));
        self::assertSame('MY', Base32::encode('f'));
        self::assertSame('MZXW6YTBOI', Base32::encode('foobar'));
    }
}
