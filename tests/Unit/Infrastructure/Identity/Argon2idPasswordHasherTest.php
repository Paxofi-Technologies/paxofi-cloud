<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Infrastructure\Identity;

use PaxofiCloud\Infrastructure\Identity\Argon2idPasswordHasher;
use PHPUnit\Framework\TestCase;

/** Threat model D-3, T-10 (PasswordHashingTest). */
final class Argon2idPasswordHasherTest extends TestCase
{
    public function testProductionParametersAreExactlyD3(): void
    {
        $hash = (new Argon2idPasswordHasher())->hash('correct horse battery staple');

        self::assertStringStartsWith('$argon2id$v=19$m=65536,t=3,p=1$', $hash);
    }

    public function testVerifiesTheRightPasswordOnly(): void
    {
        $hasher = self::fast();
        $hash = $hasher->hash('correct horse battery staple');

        self::assertTrue($hasher->verify('correct horse battery staple', $hash));
        self::assertFalse($hasher->verify('correct horse battery stapl', $hash));
    }

    public function testEveryByteOfALongPasswordCounts(): void
    {
        // bcrypt (PCF's PASSWORD_DEFAULT) would treat these two as equal.
        $hasher = self::fast();
        $prefix = str_repeat('x', 100);
        $hash = $hasher->hash($prefix . 'A');

        self::assertFalse($hasher->verify($prefix . 'B', $hash));
    }

    public function testHashesAreSaltedAndRehashIsRequestedForWeakerSettings(): void
    {
        $hasher = new Argon2idPasswordHasher();
        $weak = self::fast()->hash('same password here');

        self::assertNotSame(self::fast()->hash('same password here'), $weak);
        self::assertTrue($hasher->needsRehash($weak));
        self::assertTrue($hasher->needsRehash('$2y$04$' . str_repeat('a', 53)));
    }

    private static function fast(): Argon2idPasswordHasher
    {
        return new Argon2idPasswordHasher(8_192, 1);
    }
}
