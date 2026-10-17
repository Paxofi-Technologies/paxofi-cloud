<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Application\Identity\SignIn;

use PaxofiCloud\Application\Identity\SignIn\InvalidSignIn;
use PaxofiCloud\Application\Identity\SignIn\SignIn;
use PaxofiCloud\Application\Identity\SignIn\SignInInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SignInInputTest extends TestCase
{
    /**
     * @param array<array-key, mixed> $data
     * @param array<string, string> $errors
     */
    #[DataProvider('invalid')]
    public function testInvalidInputGivesStableFieldCodes(array $data, array $errors): void
    {
        try {
            SignInInput::fromArray($data);
            self::fail('Expected invalid input.');
        } catch (InvalidSignIn $e) {
            self::assertSame($errors, $e->errors);
        }
    }

    /** @return iterable<string, array{array<array-key, mixed>, array<string, string>}> */
    public static function invalid(): iterable
    {
        yield 'empty' => [[], ['email' => 'required', 'password' => 'required']];
        yield 'not strings' => [['email' => 1, 'password' => ['x']], ['email' => 'required', 'password' => 'required']];
        yield 'bad email' => [['email' => 'not-an-email', 'password' => 'x'], ['email' => 'invalid']];
        yield 'oversized password' => [['email' => 'a@example.ng', 'password' => str_repeat('a', 1025)], ['password' => 'too_long']];
        yield 'extra field' => [['email' => 'a@example.ng', 'password' => 'x', 'remember' => true], ['remember' => 'unknown_field']];
        yield 'odd key' => [['email' => 'a@example.ng', 'password' => 'x', 'A<b>' => 1], ['_unknown' => 'unknown_field']];
    }

    public function testShortPasswordsAreNotRejectedByPolicy(): void
    {
        // A too-short password fails verification instead, so the policy is not hinted at.
        self::assertSame('x', SignInInput::fromArray(['email' => 'a@example.ng', 'password' => 'x'])->password);
    }

    public function testDebugOutputRedactsThePassword(): void
    {
        $dump = print_r(SignInInput::fromArray(['email' => 'a@example.ng', 'password' => 'hunter2 secret']), true);

        self::assertStringNotContainsString('hunter2', $dump);
        self::assertStringNotContainsString('a@example.ng', $dump);
    }

    public function testNetworkBucketsAreSlash24AndSlash64(): void
    {
        self::assertSame(SignIn::network('198.51.100.7'), SignIn::network('198.51.100.250'));
        self::assertNotSame(SignIn::network('198.51.100.7'), SignIn::network('198.51.101.7'));
        self::assertSame(SignIn::network('2001:db8:1:2::1'), SignIn::network('2001:db8:1:2:ffff::9'));
        self::assertNotSame(SignIn::network('2001:db8:1:2::1'), SignIn::network('2001:db8:1:3::1'));
        self::assertSame('unknown', SignIn::network(''));
    }
}
