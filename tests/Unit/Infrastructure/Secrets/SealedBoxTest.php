<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Infrastructure\Secrets;

use PaxofiCloud\Infrastructure\Secrets\SealedBox;
use PHPUnit\Framework\TestCase;

/** PC-1, PC-T2: the web tier seals with the public key and cannot open anything. */
final class SealedBoxTest extends TestCase
{
    public function testOnlyTheSecretKeyOpens(): void
    {
        $pair = SealedBox::generateKeyPair();
        $sealed = SealedBox::forSealing($pair['public'])->seal('top secret');

        self::assertStringNotContainsString('top secret', $sealed);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{64,}$/', $sealed, 'fits the intake CHECK');
        self::assertSame('top secret', SealedBox::forOpening($pair['public'], $pair['secret'])->open($sealed));
    }

    public function testThePublicHalfCannotOpen(): void
    {
        $pair = SealedBox::generateKeyPair();
        $box = SealedBox::forSealing($pair['public']);

        $this->expectException(\LogicException::class);
        $box->open($box->seal('x'));
    }

    public function testAnotherKeyOrATamperedBoxFails(): void
    {
        $ours = SealedBox::generateKeyPair();
        $theirs = SealedBox::generateKeyPair();
        $opener = SealedBox::forOpening($ours['public'], $ours['secret']);

        foreach ([SealedBox::forSealing($theirs['public'])->seal('x'), substr(SealedBox::forSealing($ours['public'])->seal('x'), 0, -2) . 'AA'] as $bad) {
            try {
                $opener->open($bad);
                self::fail('foreign or altered box opened');
            } catch (\UnexpectedValueException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testMismatchedKeysAreRefusedAtStartup(): void
    {
        $a = SealedBox::generateKeyPair();
        $b = SealedBox::generateKeyPair();

        $this->expectException(\InvalidArgumentException::class);
        SealedBox::forOpening($a['public'], $b['secret']);
    }

    public function testDebugOutputHoldsNoKey(): void
    {
        $pair = SealedBox::generateKeyPair();

        self::assertSame(['canOpen' => true], SealedBox::forOpening($pair['public'], $pair['secret'])->__debugInfo());
    }
}
