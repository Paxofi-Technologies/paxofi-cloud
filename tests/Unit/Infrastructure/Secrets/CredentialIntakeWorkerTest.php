<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Infrastructure\Secrets;

use PaxofiCloud\Application\Audit\AuditEventType;
use PaxofiCloud\Application\Email\EmailTemplates;
use PaxofiCloud\Domain\Staff\StaffRole;
use PaxofiCloud\Infrastructure\Secrets\CredentialIntakeWorker;
use PaxofiCloud\Infrastructure\Secrets\ProviderCredentialWriter;
use PaxofiCloud\Infrastructure\Secrets\SealedBox;
use PaxofiCloud\Infrastructure\Secrets\StoredCredential;
use PaxofiCloud\Tests\Support\AuditFixtures;
use PaxofiCloud\Tests\Support\InMemoryAuditLog;
use PaxofiCloud\Tests\Support\InMemoryCredentialIntake;
use PaxofiCloud\Tests\Support\InMemoryStaffDirectory;
use PaxofiCloud\Tests\Support\MutableClock;
use PaxofiCloud\Tests\Support\RecordingLogger;
use PaxofiCloud\Tests\Support\RecordingOutbox;
use PHPUnit\Framework\TestCase;

/** PC-1, PC-5, PC-7, PC-T8: the worker re-checks every box, stores under the KEK, deletes, announces. PY-3: two lanes. */
final class CredentialIntakeWorkerTest extends TestCase
{
    private const string STAFF = 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1';
    private const string SECRET = 'live-token-Xy9';

    private InMemoryCredentialIntake $intake;
    private InMemoryAuditLog $audit;
    private RecordingOutbox $outbox;
    private RecordingLogger $logger;
    /** @var list<array{StoredCredential, string, string}> */
    private array $saved = [];
    /** @var array{public: string, secret: string} */
    private array $keys;
    private CredentialIntakeWorker $worker;
    private ProviderCredentialWriter $vault;
    private InMemoryStaffDirectory $directory;

    protected function setUp(): void
    {
        $this->intake = new InMemoryCredentialIntake();
        $this->audit = new InMemoryAuditLog();
        $this->outbox = new RecordingOutbox();
        $this->logger = new RecordingLogger();
        $this->keys = SealedBox::generateKeyPair();
        $directory = new InMemoryStaffDirectory();
        foreach (['m1' => ['active', StaffRole::SecurityAdmin], 'd1' => ['disabled', StaffRole::SecurityAdmin], 's1' => ['active', StaffRole::Support]] as $p => [$status, $role]) {
            $id = str_repeat($p, 16);
            $directory->add($id, $status, $role);
            $directory->profiles[$id] = ['email' => $p . '@example.test', 'full_name' => 'Staff ' . $p];
        }
        $saved = &$this->saved;
        $vault = new class ($saved) implements ProviderCredentialWriter {
            /** @param list<array{StoredCredential, string, string}> $saved */
            public function __construct(private array &$saved)
            {
            }

            /** @return list<array{StoredCredential, string, string}> */
            public function all(): array
            {
                return $this->saved;
            }

            public function save(StoredCredential $credential, string $secret, \DateTimeImmutable $reviewDueOn): void
            {
                $this->saved[] = [$credential, $secret, $reviewDueOn->format('Y-m-d')];
            }
        };
        $this->vault = $vault;
        $this->directory = $directory;
        $this->worker = $this->worker(false);
    }

    private function worker(bool $paymentGateways): CredentialIntakeWorker
    {
        return new CredentialIntakeWorker(
            'staging',
            $this->intake,
            SealedBox::forOpening($this->keys['public'], $this->keys['secret']),
            $this->vault,
            $this->directory,
            $this->outbox,
            AuditFixtures::trail($this->audit),
            new MutableClock(new \DateTimeImmutable('2026-10-20T10:00:00Z')),
            $this->logger,
            $paymentGateways,
        );
    }

    public function testTheProviderWorkerNeverTouchesGatewayRowsAndThePaymentsWorkerNeverTouchesOthers(): void
    {
        $gateway = ['credential_id' => 'paystack-staging', 'provider' => 'paystack', 'secret' => '{"secret_key":"x"}'] + $this->payload();
        $this->queue($gateway, 'paystack-staging', 'paystack');
        $this->queue($this->payload());

        self::assertSame(1, $this->worker->runOnce(), 'provider lane: hetzner only');
        self::assertSame(['hetzner-staging'], array_map(static fn (array $s): string => $s[0]->id, $this->saved));
        self::assertArrayHasKey('paystack-staging', $this->intake->rows, 'gateway row left for the payments worker');

        self::assertSame(1, $this->worker(true)->runOnce(), 'payments lane: paystack only');
        self::assertSame(['hetzner-staging', 'paystack-staging'], array_map(static fn (array $s): string => $s[0]->id, $this->saved));
        self::assertSame([], $this->intake->rows);
    }

    public function testThePaymentsWorkerRefusesAPayloadForAnotherProvider(): void
    {
        // A row filed under paystack whose sealed payload names hetzner (PC-T8): never stored under the payments key ring.
        $this->queue($this->payload(), 'paystack-staging', 'paystack');

        self::assertSame(1, $this->worker(true)->runOnce());
        self::assertSame([], $this->saved);
        self::assertSame('provider_mismatch', $this->audit->events[0]->details['reason'] ?? null);
    }

    public function testAValidSubmissionIsStoredRemovedAuditedAndAnnounced(): void
    {
        $this->queue($this->payload());

        self::assertSame(1, $this->worker->runOnce());

        self::assertCount(1, $this->saved);
        [$credential, $secret, $review] = $this->saved[0];
        self::assertSame(['hetzner-staging', 'hetzner', 'staging', 'read-write'], [$credential->id, $credential->provider, $credential->environment, $credential->accessLevel]);
        self::assertSame(self::SECRET, $secret);
        self::assertSame('2027-10-20', $review);
        self::assertSame([], $this->intake->rows);
        self::assertSame([AuditEventType::PROVIDER_CREDENTIAL_STORED], $this->audit->types());
        self::assertCount(1, $this->outbox->queued, 'only the active staff manager is told');
        self::assertSame('m1@example.test', $this->outbox->queued[0]['to']);
        self::assertSame(EmailTemplates::PROVIDER_CREDENTIAL_CHANGED, $this->outbox->queued[0]['template']);
        self::assertSame('hetzner', $this->outbox->queued[0]['parameters']['provider']);
        self::assertStringNotContainsString(self::SECRET, serialize([$this->audit->events, $this->outbox->queued, $this->logger->records]), 'PC-T6');
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function forged(): iterable
    {
        yield 'other environment' => [['environment' => 'prod', 'credential_id' => 'hetzner-prod'], 'credential_mismatch'];
        yield 'other credential id' => [['credential_id' => 'hetzner-staging-2'], 'credential_mismatch'];
        yield 'read-only' => [['access_level' => 'read-only'], 'credential_mismatch'];
        yield 'provider swapped' => [['provider' => 'vultr', 'credential_id' => 'vultr-staging'], 'provider_mismatch'];
        yield 'unknown provider' => [['provider' => 'aws'], 'provider_mismatch'];
        yield 'empty secret' => [['secret' => ''], 'secret_invalid'];
        yield 'secret not a string' => [['secret' => ['x']], 'secret_invalid'];
        yield 'other submitter' => [['submitted_by' => 'b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2'], 'submitter_mismatch'];
        yield 'old version' => [['v' => 0], 'malformed'];
    }

    /** @param array<string, mixed> $override */
    #[\PHPUnit\Framework\Attributes\DataProvider('forged')]
    public function testForgedOrMismatchedBoxesAreRejectedAndRemoved(array $override, string $reason): void
    {
        $this->queue(array_replace($this->payload(), $override));

        $this->worker->runOnce();

        self::assertSame([], $this->saved);
        self::assertSame([], $this->intake->rows);
        self::assertSame([AuditEventType::PROVIDER_CREDENTIAL_REJECTED], $this->audit->types());
        self::assertSame($reason, $this->audit->events[0]->details['reason'] ?? null);
        self::assertSame([], $this->outbox->queued);
    }

    public function testABoxSealedToAnotherKeyIsRejected(): void
    {
        $other = SealedBox::generateKeyPair();
        $sealed = SealedBox::forSealing($other['public'])->seal((string) json_encode($this->payload()));
        $this->intake->replace('hetzner-staging', 'hetzner', $sealed, self::STAFF, new \DateTimeImmutable());

        $this->worker->runOnce();

        self::assertSame([], $this->saved);
        self::assertSame('not_openable', $this->audit->events[0]->details['reason'] ?? null);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'v' => 1,
            'credential_id' => 'hetzner-staging',
            'provider' => 'hetzner',
            'environment' => 'staging',
            'access_level' => 'read-write',
            'secret' => self::SECRET,
            'submitted_by' => self::STAFF,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function queue(array $payload, string $credentialId = 'hetzner-staging', string $provider = 'hetzner'): void
    {
        $sealed = SealedBox::forSealing($this->keys['public'])->seal((string) json_encode($payload));
        $this->intake->replace($credentialId, $provider, $sealed, self::STAFF, new \DateTimeImmutable());
    }
}
