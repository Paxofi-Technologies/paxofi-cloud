<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Domain\Providers;

use PaxofiCloud\Domain\Providers\InvalidConnectionFields;
use PaxofiCloud\Domain\Providers\ProviderConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Provider connections PC-4, PC-T5: only declared fields, server-derived IDs. */
final class ProviderConnectionCatalogueTest extends TestCase
{
    public function testTwoFieldProvidersStoreAJsonObject(): void
    {
        $secret = ProviderConnection::DomainNameApi->secretFrom(['username' => 'reseller01', 'password' => 'p@ss "word" ü']);

        self::assertSame(['username' => 'reseller01', 'password' => 'p@ss "word" ü'], json_decode($secret, true));
    }

    public function testOneFieldProvidersStoreTheBareValue(): void
    {
        self::assertSame('tok-123', ProviderConnection::Hetzner->secretFrom(['api_token' => 'tok-123']));
        self::assertSame('key-456', ProviderConnection::Vultr->secretFrom(['api_key' => 'key-456']));
    }

    /** @return iterable<string, array{array<array-key, mixed>, string}> */
    public static function invalid(): iterable
    {
        yield 'missing password' => [['username' => 'reseller01'], 'password'];
        yield 'empty username' => [['username' => '', 'password' => 'x'], 'username'];
        yield 'extra field' => [['username' => 'a', 'password' => 'b', 'environment' => 'prod'], 'unknown_field'];
        yield 'credential id smuggled' => [['username' => 'a', 'password' => 'b', 'credential_id' => 'x'], 'unknown_field'];
        yield 'numeric key' => [['a', 'b'], 'unknown_field'];
        yield 'newline in password' => [['username' => 'a', 'password' => "b\nc"], 'password'];
        yield 'NUL in username' => [['username' => "a\0", 'password' => 'b'], 'username'];
        yield 'padded username' => [['username' => ' a', 'password' => 'b'], 'username'];
        yield 'not a string' => [['username' => 'a', 'password' => ['b']], 'password'];
        yield 'too long' => [['username' => str_repeat('a', 129), 'password' => 'b'], 'username'];
        yield 'invalid UTF-8' => [['username' => "a\xC3", 'password' => 'b'], 'username'];
    }

    /** @param array<array-key, mixed> $values */
    #[DataProvider('invalid')]
    public function testRefusesAnythingElseNamingTheFieldNotTheValue(array $values, string $field): void
    {
        try {
            ProviderConnection::DomainNameApi->secretFrom($values);
            self::fail('invalid fields accepted');
        } catch (InvalidConnectionFields $e) {
            self::assertSame($field, $e->getMessage());
        }
    }

    public function testIdsAndEnvironmentsAreDerived(): void
    {
        self::assertSame('domainnameapi-staging', ProviderConnection::DomainNameApi->credentialId('staging'));
        self::assertSame('prod', ProviderConnection::environmentFor('production'));
        self::assertSame('staging', ProviderConnection::environmentFor('staging'));
        self::assertSame('dev', ProviderConnection::environmentFor('local'));
        self::assertSame('dev', ProviderConnection::environmentFor('testing'));
    }
}
