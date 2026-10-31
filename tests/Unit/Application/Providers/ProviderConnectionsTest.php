<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Application\Providers;

use PaxofiCloud\Application\Audit\AuditEventType;
use PaxofiCloud\Application\Contracts\ProviderCredentialStatus;
use PaxofiCloud\Application\Providers\ProviderConnectionRefused;
use PaxofiCloud\Application\Providers\ProviderConnections;
use PaxofiCloud\Application\Staff\SignIn\StaffSecondFactor;
use PaxofiCloud\Application\Staff\StaffAuthorizer;
use PaxofiCloud\Application\Staff\StaffPermissionDenied;
use PaxofiCloud\Domain\Staff\StaffRole;
use PaxofiCloud\Infrastructure\Secrets\SealedBox;
use PaxofiCloud\Tests\Support\AuditFixtures;
use PaxofiCloud\Tests\Support\InMemoryCredentialIntake;
use PaxofiCloud\Tests\Support\MfaFixtures;
use PaxofiCloud\Tests\Support\StaffPasskeyFixtures;
use PHPUnit\Framework\TestCase;

/** THREAT-MODEL-PROVIDER-CONNECTIONS.md PC-1…PC-4, PC-T1, PC-T3…PC-T7; THREAT-MODEL-PAYMENTS.md PY-2…PY-4. */
final class ProviderConnectionsTest extends TestCase
{
    private const string PASSWORD = 'reseller-pass-Zq7!';

    private StaffPasskeyFixtures $f;
    private string $staffId;
    private InMemoryCredentialIntake $intake;
    /** @var array{public: string, secret: string} */
    private array $keys;
    /** @var array{public: string, secret: string} */
    private array $paymentKeys;
    private ProviderConnections $connections;
    /** @var array<string, array{updated_at: \DateTimeImmutable, review_due_on: \DateTimeImmutable}> */
    private array $configured = [];

    protected function setUp(): void
    {
        $this->f = new StaffPasskeyFixtures();
        $this->staffId = $this->f->invitations->bootstrap('ada@example.test', 'Ada Lovelace', AuditFixtures::client());
        $this->f->activate($this->f->lastToken());
        $this->f->audit->events = [];
        $this->intake = new InMemoryCredentialIntake();
        $this->keys = SealedBox::generateKeyPair();
        $this->paymentKeys = SealedBox::generateKeyPair();
        $this->connections = $this->service(SealedBox::forSealing($this->keys['public']));
    }

    public function testASubmissionIsSealedForTheWorkerAndAudited(): void
    {
        $this->submit(['reseller_id' => '0b6f1c2e-1111-4222-8333-444455556666', 'api_key' => self::PASSWORD]);

        $row = $this->intake->rows['domainnameapi-staging'] ?? null;
        self::assertNotNull($row);
        self::assertStringNotContainsString(self::PASSWORD, $row['sealed']);
        $payload = json_decode(SealedBox::forOpening($this->keys['public'], $this->keys['secret'])->open($row['sealed']), true);
        self::assertIsArray($payload);
        self::assertSame('domainnameapi-staging', $payload['credential_id']);
        self::assertSame('staging', $payload['environment']);
        self::assertSame('read-write', $payload['access_level']);
        self::assertSame($this->staffId, $payload['submitted_by']);
        self::assertIsString($payload['secret']);
        self::assertSame(['reseller_id' => '0b6f1c2e-1111-4222-8333-444455556666', 'api_key' => self::PASSWORD], json_decode($payload['secret'], true));

        $last = end($this->f->audit->events);
        self::assertNotFalse($last);
        self::assertSame(AuditEventType::PROVIDER_CREDENTIAL_SUBMITTED, $last->type);
        self::assertSame(['subject' => $this->staffId, 'provider' => 'domainnameapi', 'credential_id' => 'domainnameapi-staging'], $last->details);
        self::assertStringNotContainsString(self::PASSWORD, serialize($this->f->audit->events), 'PC-T6');
        self::assertStringNotContainsString(self::PASSWORD, serialize($this->f->logger->records), 'PC-T6');
    }

    public function testANewerSubmissionReplacesAPendingOne(): void
    {
        $this->submit(['reseller_id' => 'first', 'api_key' => 'one']);
        $this->submit(['reseller_id' => 'second', 'api_key' => 'two']);

        self::assertCount(1, $this->intake->rows);
    }

    public function testTheOverviewShowsStatusButNeverASecret(): void
    {
        $this->configured['hetzner-staging'] = ['updated_at' => new \DateTimeImmutable('2026-10-20T08:00:00Z'), 'review_due_on' => new \DateTimeImmutable('2027-10-20')];
        $this->submit(['reseller_id' => '0b6f1c2e-1111-4222-8333-444455556666', 'api_key' => self::PASSWORD]);

        $overview = $this->connections->overview($this->staffId, AuditFixtures::client());
        $byProvider = array_column($overview, null, 'provider');

        self::assertSame(['domainnameapi', 'namecheap', 'dynadot', 'spaceship', 'hetzner', 'vultr', 'paystack', 'flutterwave'], array_keys($byProvider));
        self::assertSame(['domains', 'domains', 'domains', 'domains', 'servers', 'servers', 'payments', 'payments'], array_column($overview, 'category'));
        self::assertSame(['secret_key', 'webhook_secret_hash'], array_column($byProvider['flutterwave']['fields'], 'name'));
        self::assertSame(['api_key', 'api_secret'], array_column($byProvider['spaceship']['fields'], 'name'));
        self::assertSame([true, true], array_column($byProvider['spaceship']['fields'], 'secret'));
        self::assertStringContainsString('domains:read', (string) $byProvider['spaceship']['fields'][0]['hint'], 'SP-1: the hint asks for a read-only key');
        self::assertNotNull($byProvider['domainnameapi']['pending_since']);
        self::assertNull($byProvider['domainnameapi']['configured_at']);
        self::assertSame('2027-10-20', $byProvider['hetzner']['review_due_on']);
        self::assertSame(['reseller_id', 'api_key'], array_column($byProvider['domainnameapi']['fields'], 'name'));
        self::assertSame([false, true], array_column($byProvider['domainnameapi']['fields'], 'secret'));
        self::assertStringContainsString('Integration Information', (string) $byProvider['domainnameapi']['fields'][0]['hint']);
        self::assertStringNotContainsString(self::PASSWORD, (string) json_encode($overview), 'PC-T1: no read path');
        self::assertStringNotContainsString('0b6f1c2e-1111-4222-8333-444455556666', (string) json_encode($overview));
    }

    public function testAWrongMissingOrReusedCodeIsRefused(): void
    {
        $this->expectRefused(ProviderConnectionRefused::STEP_UP, fn () => $this->connections->submit($this->staffId, 'hetzner', ['api_token' => 't'], '000000', AuditFixtures::client()));
        $this->expectRefused(ProviderConnectionRefused::STEP_UP, fn () => $this->connections->submit($this->staffId, 'hetzner', ['api_token' => 't'], '', AuditFixtures::client()));

        $this->f->clock->advance('+31 seconds');
        $code = $this->f->totpCode($this->staffId);
        $this->connections->submit($this->staffId, 'hetzner', ['api_token' => 't'], $code, AuditFixtures::client());
        $this->expectRefused(ProviderConnectionRefused::STEP_UP, fn () => $this->connections->submit($this->staffId, 'hetzner', ['api_token' => 't2'], $code, AuditFixtures::client()));
        self::assertCount(1, $this->intake->rows);
    }

    public function testWithoutStaffSecondFactorsNothingCanBeSubmitted(): void
    {
        $service = $this->service(SealedBox::forSealing($this->keys['public']), withSecondFactor: false);
        $this->f->clock->advance('+31 seconds');

        $this->expectRefused(ProviderConnectionRefused::STEP_UP, fn () => $service->submit($this->staffId, 'hetzner', ['api_token' => 't'], $this->f->totpCode($this->staffId), AuditFixtures::client()));
    }

    public function testUnknownProvidersAndBadFieldsAreRefused(): void
    {
        $this->expectRefused(ProviderConnectionRefused::UNKNOWN_PROVIDER, fn () => $this->connections->submit($this->staffId, 'aws', ['k' => 'v'], '123456', AuditFixtures::client()));
        $this->f->clock->advance('+31 seconds');
        try {
            $this->connections->submit($this->staffId, 'domainnameapi', ['reseller_id' => 'a', 'api_key' => 'b', 'environment' => 'prod'], $this->f->totpCode($this->staffId), AuditFixtures::client());
            self::fail('extra field accepted');
        } catch (ProviderConnectionRefused $e) {
            self::assertSame(ProviderConnectionRefused::INVALID_FIELDS, $e->reason);
            self::assertSame('unknown_field', $e->field);
        }
        self::assertSame([], $this->intake->rows);
    }

    public function testWithoutAnIntakeKeyNothingIsStored(): void
    {
        $service = $this->service(null);
        $this->f->clock->advance('+31 seconds');

        $this->expectRefused(ProviderConnectionRefused::UNAVAILABLE, fn () => $service->submit($this->staffId, 'hetzner', ['api_token' => 't'], $this->f->totpCode($this->staffId), AuditFixtures::client()));
    }

    public function testGatewayKeysAreSealedToThePaymentsKeyNotTheProviderKey(): void
    {
        $key = 'sk_test_' . str_repeat('0a1b2c3d', 5);
        $this->f->clock->advance('+31 seconds');
        $this->connections->submit($this->staffId, 'paystack', ['secret_key' => $key], $this->f->totpCode($this->staffId), AuditFixtures::client());

        $row = $this->intake->rows['paystack-staging'] ?? null;
        self::assertNotNull($row);
        self::assertStringNotContainsString($key, $row['sealed']);
        try {
            SealedBox::forOpening($this->keys['public'], $this->keys['secret'])->open($row['sealed']);
            self::fail('PY-3: the provider worker opened a gateway key');
        } catch (\UnexpectedValueException) {
            $this->addToAssertionCount(1);
        }
        $payload = json_decode(SealedBox::forOpening($this->paymentKeys['public'], $this->paymentKeys['secret'], 'PAYMENTS_INTAKE')->open($row['sealed']), true);
        self::assertIsArray($payload);
        self::assertSame('paystack-staging', $payload['credential_id']);
        self::assertIsString($payload['secret']);
        self::assertSame(['secret_key' => $key], json_decode($payload['secret'], true), 'gateway secrets are always the JSON object GatewayCredential reads');
        self::assertStringNotContainsString($key, serialize($this->f->audit->events));
    }

    public function testAGatewayKeyOfTheWrongModeIsRefusedAtIntake(): void
    {
        // PY-4: staging takes test keys only; production takes live keys only.
        $this->f->clock->advance('+31 seconds');
        $this->expectRefused(ProviderConnectionRefused::WRONG_KEY_MODE, fn () => $this->connections->submit($this->staffId, 'paystack', ['secret_key' => 'sk_live_' . str_repeat('0a1b2c3d', 5)], $this->f->totpCode($this->staffId), AuditFixtures::client()));
        $this->f->clock->advance('+31 seconds');
        $this->expectRefused(ProviderConnectionRefused::INVALID_FIELDS, fn () => $this->connections->submit($this->staffId, 'paystack', ['secret_key' => 'pk_test_' . str_repeat('0a1b2c3d', 5)], $this->f->totpCode($this->staffId), AuditFixtures::client()));

        $production = $this->service(SealedBox::forSealing($this->keys['public']), environment: 'prod');
        $this->f->clock->advance('+31 seconds');
        $this->expectRefused(ProviderConnectionRefused::WRONG_KEY_MODE, fn () => $production->submit($this->staffId, 'flutterwave', [
            'secret_key' => 'FLWSECK_TEST-' . str_repeat('0a1b2c3d', 4) . '-X',
            'webhook_secret_hash' => str_repeat('h', 40),
        ], $this->f->totpCode($this->staffId), AuditFixtures::client()));
        self::assertSame([], $this->intake->rows);
    }

    public function testWithoutThePaymentsIntakeKeyGatewayKeysCannotBeSaved(): void
    {
        $service = $this->service(SealedBox::forSealing($this->keys['public']), withPayments: false);
        $this->f->clock->advance('+31 seconds');

        $this->expectRefused(ProviderConnectionRefused::UNAVAILABLE, fn () => $service->submit($this->staffId, 'paystack', ['secret_key' => 'sk_test_' . str_repeat('0a1b2c3d', 5)], $this->f->totpCode($this->staffId), AuditFixtures::client()));
        self::assertSame([], $this->intake->rows, 'never falls back to the provider intake key');
    }

    public function testSubmissionsAreRateLimitedPerStaffMember(): void
    {
        for ($i = 0; $i < ProviderConnections::ATTEMPTS_PER_WINDOW; $i++) {
            try {
                $this->connections->submit($this->staffId, 'hetzner', ['api_token' => 't'], '000000', AuditFixtures::client());
            } catch (ProviderConnectionRefused) {
            }
        }
        $this->expectRefused(ProviderConnectionRefused::THROTTLED, fn () => $this->connections->submit($this->staffId, 'hetzner', ['api_token' => 't'], '000000', AuditFixtures::client()));
    }

    public function testStaffWithoutThePermissionCannotSeeOrSubmit(): void
    {
        $this->f->directory->add('b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0', 'active', StaffRole::Support);

        foreach ([
            fn () => $this->connections->overview('b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0', AuditFixtures::client()),
            fn () => $this->connections->submit('b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0', 'hetzner', ['api_token' => 't'], '123456', AuditFixtures::client()),
        ] as $action) {
            try {
                $action();
                self::fail('permission not enforced');
            } catch (StaffPermissionDenied) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame([], $this->intake->rows);
    }

    /** @param array<string, string> $fields */
    private function submit(array $fields): void
    {
        $this->f->clock->advance('+31 seconds');
        $this->connections->submit($this->staffId, 'domainnameapi', $fields, $this->f->totpCode($this->staffId), AuditFixtures::client());
    }

    private function expectRefused(string $reason, \Closure $action): void
    {
        try {
            $action();
            self::fail('Expected refusal: ' . $reason);
        } catch (ProviderConnectionRefused $e) {
            self::assertSame($reason, $e->reason);
        }
        $last = end($this->f->audit->events);
        self::assertNotFalse($last);
        self::assertSame(AuditEventType::PROVIDER_CREDENTIAL_REFUSED, $last->type);
        self::assertSame($reason, $last->details['reason'] ?? null);
    }

    /** @param 'dev'|'staging'|'prod' $environment */
    private function service(?SealedBox $sealer, bool $withSecondFactor = true, bool $withPayments = true, string $environment = 'staging'): ProviderConnections
    {
        $configured = &$this->configured;
        $status = new class ($configured) implements ProviderCredentialStatus {
            /** @param array<string, array{updated_at: \DateTimeImmutable, review_due_on: \DateTimeImmutable}> $configured */
            public function __construct(private array &$configured)
            {
            }

            public function configured(): array
            {
                return $this->configured;
            }
        };
        $trail = AuditFixtures::trail($this->f->audit);

        return new ProviderConnections(
            $environment,
            new StaffAuthorizer($this->f->directory, $trail),
            $withSecondFactor ? new StaffSecondFactor($this->f->credentials, MfaFixtures::crypto(), $this->f->clock) : null,
            $sealer,
            $this->intake,
            $status,
            $this->f->limiter,
            $this->f->clock,
            $trail,
            $withPayments ? SealedBox::forSealing($this->paymentKeys['public'], 'PAYMENTS_INTAKE') : null,
        );
    }
}
