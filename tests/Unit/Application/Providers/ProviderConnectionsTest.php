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

/** THREAT-MODEL-PROVIDER-CONNECTIONS.md PC-1…PC-4, PC-T1, PC-T3…PC-T7. */
final class ProviderConnectionsTest extends TestCase
{
    private const string PASSWORD = 'reseller-pass-Zq7!';

    private StaffPasskeyFixtures $f;
    private string $staffId;
    private InMemoryCredentialIntake $intake;
    /** @var array{public: string, secret: string} */
    private array $keys;
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

        self::assertSame(['domainnameapi', 'namecheap', 'hetzner', 'vultr'], array_keys($byProvider));
        self::assertSame(['domains', 'domains', 'servers', 'servers'], array_column($overview, 'category'));
        self::assertNotNull($byProvider['domainnameapi']['pending_since']);
        self::assertNull($byProvider['domainnameapi']['configured_at']);
        self::assertSame('2027-10-20', $byProvider['hetzner']['review_due_on']);
        self::assertSame([['name' => 'reseller_id', 'label' => 'Reseller ID', 'secret' => false], ['name' => 'api_key', 'label' => 'API key', 'secret' => true]], $byProvider['domainnameapi']['fields']);
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

    private function service(?SealedBox $sealer, bool $withSecondFactor = true): ProviderConnections
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
            'staging',
            new StaffAuthorizer($this->f->directory, $trail),
            $withSecondFactor ? new StaffSecondFactor($this->f->credentials, MfaFixtures::crypto(), $this->f->clock) : null,
            $sealer,
            $this->intake,
            $status,
            $this->f->limiter,
            $this->f->clock,
            $trail,
        );
    }
}
