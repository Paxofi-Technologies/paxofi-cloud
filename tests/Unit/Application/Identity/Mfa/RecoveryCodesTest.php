<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Application\Identity\Mfa;

use PaxofiCloud\Application\Identity\Mfa\RecoveryCodes;
use PHPUnit\Framework\TestCase;

/** Threat model D-1, D-5. */
final class RecoveryCodesTest extends TestCase
{
    public function testTenDistinctEightyBitCodesInAReadableFormat(): void
    {
        $codes = RecoveryCodes::generate();

        self::assertCount(10, $codes);
        self::assertCount(10, array_unique($codes));
        foreach ($codes as $code) {
            self::assertMatchesRegularExpression('/^[A-Z2-7]{4}-[A-Z2-7]{4}-[A-Z2-7]{4}-[A-Z2-7]{4}$/', $code);
        }
    }

    public function testHashIsKeyedAndIgnoresCaseAndDashes(): void
    {
        $a = new RecoveryCodes(str_repeat("\x01", 32));
        $b = new RecoveryCodes(str_repeat("\x02", 32));

        $hash = $a->hash('ABCD-EFGH-IJKL-MNOP');
        self::assertIsString($hash);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
        self::assertSame($hash, $a->hash(' abcd efgh-ijkl mnop '));
        self::assertNotSame($hash, $b->hash('ABCD-EFGH-IJKL-MNOP'), 'Without the server key the stored hash is useless');
        self::assertNull($a->hash('ABCD-EFGH-IJKL-MNO'));
        self::assertNull($a->hash('ABCD-EFGH-IJKL-MNO1'));
    }

    public function testTheKeyMustBe32Bytes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RecoveryCodes('short');
    }
}
