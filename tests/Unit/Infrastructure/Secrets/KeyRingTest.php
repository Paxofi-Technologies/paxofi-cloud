<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Infrastructure\Secrets;

use PaxofiCloud\Infrastructure\Secrets\InvalidKeyRing;
use PaxofiCloud\Infrastructure\Secrets\KeyRing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class KeyRingTest extends TestCase
{
    public function testParsesTheEnvironmentFormat(): void
    {
        $ring = KeyRing::fromEnvironment(KeyRing::generateEntry('k2026a') . ', ' . KeyRing::generateEntry('k2025b'), 'k2026a');

        self::assertSame('k2026a', $ring->activeKeyId);
        self::assertSame(32, strlen($ring->activeKey()));
        self::assertNotNull($ring->key('k2025b'));
        self::assertNull($ring->key('missing'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalid(): iterable
    {
        $good = KeyRing::generateEntry('k1');
        yield 'empty list' => ['', 'k1'];
        yield 'missing separator' => ['k1', 'k1'];
        yield 'short key' => ['k1:' . base64_encode(str_repeat('x', 16)), 'k1'];
        yield 'not base64' => ['k1:***', 'k1'];
        yield 'bad id' => ['K_1:' . base64_encode(random_bytes(32)), 'K_1'];
        yield 'active key missing' => [$good, 'k2'];
        yield 'duplicate id' => [$good . ',' . $good, 'k1'];
        yield 'same material under two ids' => [$good . ',k2:' . explode(':', $good)[1], 'k1'];
    }

    #[DataProvider('invalid')]
    public function testRejectsMisconfiguration(string $list, string $active): void
    {
        $this->expectException(InvalidKeyRing::class);

        KeyRing::fromEnvironment($list, $active);
    }

    public function testKeysNeverAppearInDebugOutputOrSerialisation(): void
    {
        $entry = KeyRing::generateEntry('k1');
        $ring = KeyRing::fromEnvironment($entry, 'k1');

        self::assertStringNotContainsString(explode(':', $entry)[1], print_r($ring, true));
        self::assertStringNotContainsString($ring->activeKey(), print_r($ring, true));

        $this->expectException(\LogicException::class);
        serialize($ring);
    }
}
