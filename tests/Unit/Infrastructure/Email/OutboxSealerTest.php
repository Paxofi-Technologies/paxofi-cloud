<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Infrastructure\Email;

use PaxofiCloud\Infrastructure\Email\OutboxSealer;
use PHPUnit\Framework\TestCase;

/** M-01: sealed payloads hide tokens; only the secret-key holder can open them. */
final class OutboxSealerTest extends TestCase
{
    public function testWorkerOpensWhatTheWebTierSealed(): void
    {
        $pair = OutboxSealer::generateKeyPair();
        $sealed = OutboxSealer::forSealing($pair['public'])->seal('{"token":"one-time-secret-token"}');

        self::assertStringNotContainsString('one-time-secret-token', $sealed);
        self::assertSame('{"token":"one-time-secret-token"}', OutboxSealer::forOpening($pair['public'], $pair['secret'])->open($sealed));
    }

    public function testTheWebTierCannotOpenPayloads(): void
    {
        $pair = OutboxSealer::generateKeyPair();
        $web = OutboxSealer::forSealing($pair['public']);

        $this->expectException(\LogicException::class);
        $web->open($web->seal('x'));
    }

    public function testTamperedOrForeignPayloadsAreRejected(): void
    {
        $pair = OutboxSealer::generateKeyPair();
        $other = OutboxSealer::generateKeyPair();
        $worker = OutboxSealer::forOpening($pair['public'], $pair['secret']);

        foreach ([
            OutboxSealer::forSealing($other['public'])->seal('x'),
            substr(OutboxSealer::forSealing($pair['public'])->seal('xyz'), 0, -2) . 'AA',
            'not-base64!!',
        ] as $bad) {
            try {
                $worker->open($bad);
                self::fail('Opened a payload it should not');
            } catch (\UnexpectedValueException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testMismatchedKeysAndBadEncodingsAreRefused(): void
    {
        $a = OutboxSealer::generateKeyPair();
        $b = OutboxSealer::generateKeyPair();
        foreach ([fn () => OutboxSealer::forOpening($a['public'], $b['secret']), fn () => OutboxSealer::forSealing('c2hvcnQ=')] as $build) {
            try {
                $build();
                self::fail('Accepted invalid keys');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testDebugOutputHidesKeys(): void
    {
        $pair = OutboxSealer::generateKeyPair();
        $dump = print_r(OutboxSealer::forOpening($pair['public'], $pair['secret']), true);

        self::assertStringNotContainsString($pair['secret'], $dump);
        self::assertStringContainsString('canOpen', $dump);
    }
}
