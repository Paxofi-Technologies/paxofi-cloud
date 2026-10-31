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
        $secret = ProviderConnection::DomainNameApi->secretFrom(['reseller_id' => '0b6f1c2e-1111-4222-8333-444455556666', 'api_key' => 'p@ss "word" ü']);

        self::assertSame(['reseller_id' => '0b6f1c2e-1111-4222-8333-444455556666', 'api_key' => 'p@ss "word" ü'], json_decode($secret, true));
    }

    public function testOneFieldProvidersStoreTheBareValue(): void
    {
        self::assertSame('tok-123', ProviderConnection::Hetzner->secretFrom(['api_token' => 'tok-123']));
        self::assertSame('key-456', ProviderConnection::Vultr->secretFrom(['api_key' => 'key-456']));
    }

    /** @return iterable<string, array{array<array-key, mixed>, string}> */
    public static function invalid(): iterable
    {
        yield 'missing API key' => [['reseller_id' => '0b6f1c2e-1111-4222-8333-444455556666'], 'api_key'];
        yield 'empty reseller ID' => [['reseller_id' => '', 'api_key' => 'x'], 'reseller_id'];
        yield 'extra field' => [['reseller_id' => 'a', 'api_key' => 'b', 'environment' => 'prod'], 'unknown_field'];
        yield 'credential id smuggled' => [['reseller_id' => 'a', 'api_key' => 'b', 'credential_id' => 'x'], 'unknown_field'];
        yield 'numeric key' => [['a', 'b'], 'unknown_field'];
        yield 'newline in API key' => [['reseller_id' => 'a', 'api_key' => "b\nc"], 'api_key'];
        yield 'NUL in reseller ID' => [['reseller_id' => "a\0", 'api_key' => 'b'], 'reseller_id'];
        yield 'padded reseller ID' => [['reseller_id' => ' a', 'api_key' => 'b'], 'reseller_id'];
        yield 'not a string' => [['reseller_id' => 'a', 'api_key' => ['b']], 'api_key'];
        yield 'too long' => [['reseller_id' => str_repeat('a', 65), 'api_key' => 'b'], 'reseller_id'];
        yield 'invalid UTF-8' => [['reseller_id' => "a\xC3", 'api_key' => 'b'], 'reseller_id'];
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

    public function testNamecheapTakesFourFieldsInOrderAndAnIpv4ClientAddress(): void
    {
        $values = ['api_user' => 'paxofi', 'username' => 'paxofi', 'api_key' => 'k3y-value', 'client_ip' => '8.8.4.4'];
        self::assertSame($values, json_decode(ProviderConnection::Namecheap->secretFrom($values), true));

        foreach (['8.8.4', '8.8.4.4 ', '::1', '256.1.1.1', '08.8.4.4', 'a.b.c.d'] as $ip) {
            try {
                ProviderConnection::Namecheap->secretFrom(['client_ip' => $ip] + $values);
                self::fail($ip . ' accepted');
            } catch (InvalidConnectionFields $e) {
                self::assertSame('client_ip', $e->getMessage());
            }
        }
        try {
            ProviderConnection::Namecheap->secretFrom(['api_user' => 'pax ofi'] + $values);
            self::fail('space accepted');
        } catch (InvalidConnectionFields $e) {
            self::assertSame('api_user', $e->getMessage());
        }
    }

    public function testEveryFieldTellsStaffWhereToFindTheValue(): void
    {
        foreach (ProviderConnection::cases() as $provider) {
            foreach ($provider->fields() as $field) {
                self::assertNotSame('', trim((string) $field->hint), $provider->value . '.' . $field->name);
            }
        }
        self::assertStringContainsString('username', (string) ProviderConnection::Namecheap->fields()[0]->hint);
        self::assertSame(['domains' => 'Domain registrars', 'servers' => 'Server providers', 'payments' => 'Payment gateways'], ProviderConnection::CATEGORIES);
    }

    public function testEveryConnectionHasAKnownCategory(): void
    {
        foreach (ProviderConnection::cases() as $provider) {
            self::assertArrayHasKey($provider->category(), ProviderConnection::CATEGORIES, $provider->value);
        }
        self::assertSame('domains', ProviderConnection::Namecheap->category());
        self::assertSame('domains', ProviderConnection::DomainNameApi->category());
        self::assertSame('servers', ProviderConnection::Hetzner->category());
        self::assertSame('servers', ProviderConnection::Vultr->category());
        self::assertSame('payments', ProviderConnection::Paystack->category());
        self::assertSame('payments', ProviderConnection::Flutterwave->category());
    }

    public function testOnlyPaymentGatewaysGoToThePaymentsLane(): void
    {
        $lane = array_map(static fn (ProviderConnection $p): bool => $p->isPaymentGateway(), ProviderConnection::cases());
        self::assertSame(['paystack', 'flutterwave'], array_values(array_map(static fn (ProviderConnection $p): string => $p->value, array_filter(ProviderConnection::cases(), static fn (ProviderConnection $p): bool => $p->isPaymentGateway()))));
        self::assertCount(8, $lane);
    }

    public function testGatewaySecretsAreAlwaysJsonAndOnlySecretKeysAreAccepted(): void
    {
        $key = 'sk_test_' . str_repeat('0a1b2c3d', 5);
        self::assertSame(['secret_key' => $key], json_decode(ProviderConnection::Paystack->secretFrom(['secret_key' => $key]), true));
        foreach (['pk_test_' . str_repeat('0a1b2c3d', 5), 'FLWSECK_TEST-' . str_repeat('0a1b2c3d', 4) . '-X', $key . "\n"] as $bad) {
            try {
                ProviderConnection::Paystack->secretFrom(['secret_key' => $bad]);
                self::fail('accepted ' . substr($bad, 0, 8));
            } catch (InvalidConnectionFields $e) {
                self::assertSame('secret_key', $e->getMessage());
            }
        }
        try {
            ProviderConnection::Flutterwave->secretFrom(['secret_key' => 'FLWSECK_TEST-' . str_repeat('0a1b2c3d', 4) . '-X', 'webhook_secret_hash' => 'too-short']);
            self::fail('short hash accepted');
        } catch (InvalidConnectionFields $e) {
            self::assertSame('webhook_secret_hash', $e->getMessage());
        }
    }
}
