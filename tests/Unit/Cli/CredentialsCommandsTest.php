<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Cli;

use PaxofiCloud\Cli\CredentialsListCommand;
use PaxofiCloud\Cli\CredentialsReencryptCommand;
use PaxofiCloud\Cli\CredentialsSetCommand;
use PaxofiCloud\Cli\CredentialStoreAccess;
use PaxofiCloud\Infrastructure\Secrets\InvalidKeyRing;
use PaxofiCloud\Infrastructure\Secrets\MysqlProviderCredentialStore;
use PaxofiCloud\Tests\Support\ConsoleStreams;
use PaxofiCloud\Tests\Support\FixedClock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Argument and key-ring failures; the store itself is exercised against real
 * MySQL in CredentialsCliIntegrationTest.
 */
final class CredentialsCommandsTest extends TestCase
{
    private const string SECRET = 'tok-not-a-real-secret-123';

    /** @return iterable<string, array{list<string>, string}> */
    public static function invalidArguments(): iterable
    {
        yield 'too few' => [['hetzner-prod', 'hetzner', 'prod', 'read-write'], 'Usage:'];
        yield 'too many' => [['hetzner-prod', 'hetzner', 'prod', 'read-write', '2027-01-01', 'extra'], 'Usage:'];
        yield 'bad id' => [['Hetzner Prod', 'hetzner', 'prod', 'read-write', '2027-01-01'], 'Credential IDs'];
        yield 'bad environment' => [['hetzner-prod', 'hetzner', 'production', 'read-write', '2027-01-01'], 'Environment'];
        yield 'bad access level' => [['hetzner-prod', 'hetzner', 'prod', 'admin', '2027-01-01'], 'Access level'];
        yield 'not a date' => [['hetzner-prod', 'hetzner', 'prod', 'read-write', 'next-year'], 'valid YYYY-MM-DD'];
        yield 'impossible date' => [['hetzner-prod', 'hetzner', 'prod', 'read-write', '2027-02-30'], 'valid YYYY-MM-DD'];
        yield 'today' => [['hetzner-prod', 'hetzner', 'prod', 'read-write', '2026-10-06'], 'after today'];
        yield 'past' => [['hetzner-prod', 'hetzner', 'prod', 'read-write', '2026-01-01'], 'after today'];
        yield 'more than a year ahead' => [['hetzner-prod', 'hetzner', 'prod', 'read-write', '2027-10-07'], 'at most one year'];
    }

    /** @param list<string> $arguments */
    #[DataProvider('invalidArguments')]
    public function testSetRejectsInvalidArgumentsBeforeTouchingKeysOrInput(array $arguments, string $message): void
    {
        $streams = new ConsoleStreams(self::SECRET);
        $command = new CredentialsSetCommand(self::unreachableStore(), $streams->console(), self::clock());

        self::assertSame(1, $command->execute($arguments));
        self::assertStringContainsString($message, $streams->errors());
        self::assertSame('', $streams->output());
    }

    public function testSetAcceptsAReviewDateExactlyOneYearAhead(): void
    {
        // Validation passes, so the command goes on to build the store; that is where this stops.
        $streams = new ConsoleStreams(self::SECRET);
        $command = new CredentialsSetCommand(self::missingKeys(), $streams->console(), self::clock());

        self::assertSame(1, $command->execute(['hetzner-prod', 'hetzner', 'prod', 'read-write', '2027-10-06']));
        self::assertStringContainsString('PROVIDER_CREDENTIAL_KEYS', $streams->errors());
    }

    public function testEveryCommandFailsCleanlyWithoutKeys(): void
    {
        foreach ([
            fn (ConsoleStreams $s): int => (new CredentialsSetCommand(self::missingKeys(), $s->console(), self::clock()))->execute(['hetzner-prod', 'hetzner', 'prod', 'read-write', '2027-01-01']),
            fn (ConsoleStreams $s): int => (new CredentialsListCommand(self::missingKeys(), $s->console(), self::clock()))->execute([]),
            fn (ConsoleStreams $s): int => (new CredentialsReencryptCommand(self::missingKeys(), $s->console()))->execute([]),
        ] as $run) {
            $streams = new ConsoleStreams(self::SECRET);
            self::assertSame(1, $run($streams));
            self::assertStringContainsString('PROVIDER_CREDENTIAL_KEYS', $streams->errors());
            self::assertStringNotContainsString(self::SECRET, $streams->errors() . $streams->output());
        }
    }

    private static function clock(): FixedClock
    {
        return new FixedClock(new \DateTimeImmutable('2026-10-06T23:30:00+00:00'));
    }

    private static function unreachableStore(): CredentialStoreAccess
    {
        return new CredentialStoreAccess(static fn (): MysqlProviderCredentialStore => throw new \LogicException('The store must not be built for invalid input.'));
    }

    private static function missingKeys(): CredentialStoreAccess
    {
        return new CredentialStoreAccess(static fn (): MysqlProviderCredentialStore => throw new InvalidKeyRing('PROVIDER_CREDENTIAL_KEYS and PROVIDER_CREDENTIAL_ACTIVE_KEY must be set (workers and operator CLI only).'));
    }
}
