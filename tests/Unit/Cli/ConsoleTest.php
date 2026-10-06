<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Cli;

use PaxofiCloud\Cli\Console;
use PaxofiCloud\Tests\Support\ConsoleStreams;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConsoleTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function acceptedInputs(): iterable
    {
        yield 'no line ending' => ['tok_abc-123.XYZ'];
        yield 'LF' => ["tok_abc-123.XYZ\n"];
        yield 'CRLF' => ["tok_abc-123.XYZ\r\n"];
    }

    #[DataProvider('acceptedInputs')]
    public function testReadsOneSecretAndStripsOneLineEnding(string $input): void
    {
        self::assertSame('tok_abc-123.XYZ', (new ConsoleStreams($input))->console()->readSecret());
    }

    /** @return iterable<string, array{string, string}> */
    public static function rejectedInputs(): iterable
    {
        yield 'empty' => ['', 'empty'];
        yield 'only a newline' => ["\n", 'empty'];
        yield 'two lines' => ["tok-secret-one\ntok-secret-two\n", 'whitespace'];
        yield 'inner space' => ['tok secret-value', 'whitespace'];
        yield 'trailing space' => ["tok-secret-value \n", 'whitespace'];
        yield 'control character' => ["tok-secret\x07value", 'control'];
        yield 'non-ASCII' => ["tok-secr\u{e9}t-value", 'non-ASCII'];
        yield 'too long' => [str_repeat('a', Console::MAX_SECRET_BYTES + 1), 'longer than'];
    }

    #[DataProvider('rejectedInputs')]
    public function testRejectsMalformedSecretsWithoutEchoingThem(string $input, string $reason): void
    {
        try {
            (new ConsoleStreams($input))->console()->readSecret();
            self::fail('Expected the secret to be rejected.');
        } catch (\UnexpectedValueException $error) {
            self::assertStringContainsString($reason, $error->getMessage());
            self::assertStringNotContainsString('tok', $error->getMessage());
            self::assertStringNotContainsString('aaaa', $error->getMessage());
        }
    }

    public function testAcceptsASecretOfExactlyTheMaximumLength(): void
    {
        $secret = str_repeat('a', Console::MAX_SECRET_BYTES);

        self::assertSame($secret, (new ConsoleStreams($secret . "\n"))->console()->readSecret());
    }

    public function testRefusesWhenThereIsNoInputStream(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        (new ConsoleStreams())->console()->readSecret();
    }
}
