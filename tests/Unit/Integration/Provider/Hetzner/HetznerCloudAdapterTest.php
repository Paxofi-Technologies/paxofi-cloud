<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Integration\Provider\Hetzner;

use PaxofiCloud\Integration\Provider\Hetzner\HetznerCloudAdapter;
use PaxofiCloud\Integration\Provider\ProviderCredential;
use PaxofiCloud\Integration\Provider\ProviderError;
use PaxofiCloud\Integration\Provider\ProviderErrorClass;
use PaxofiCloud\Integration\Provider\Vps\CreateVpsServer;
use PaxofiCloud\Integration\Provider\Vps\OwnershipMismatch;
use PaxofiCloud\Integration\Provider\Vps\PowerAction;
use PaxofiCloud\Integration\Provider\Vps\ServerRef;
use PaxofiCloud\Integration\Provider\Vps\SshPublicKey;
use PaxofiCloud\Integration\Provider\Vps\VpsActionStatus;
use PaxofiCloud\Integration\Provider\Vps\VpsServerStatus;
use PaxofiCloud\Tests\Support\FakeProviderTransport;
use PHPUnit\Framework\TestCase;

/**
 * Hetzner Cloud adapter against a scripted fake API (threat model §7:
 * IdempotentCreate, CreateTimeoutThenRetry, DestructiveActionLabelGuard,
 * ProviderErrorMapping, ProviderLogRedaction). Fixture data uses
 * documentation IP ranges and invented IDs only.
 */
final class HetznerCloudAdapterTest extends TestCase
{
    private const string SECRET = 'test-token-not-real-abcdef0123456789';
    private const string SELECTOR = 'paxo-svc==42,paxo-env==prod';

    private FakeProviderTransport $api;
    private HetznerCloudAdapter $adapter;

    protected function setUp(): void
    {
        $this->api = new FakeProviderTransport();
        $this->adapter = new HetznerCloudAdapter(
            $this->api,
            new ProviderCredential('hetzner-prod-customers', self::SECRET),
            'prod',
            static fn (): int => 1_700_000_000,
        );
    }

    protected function tearDown(): void
    {
        $this->api->assertScriptConsumed();
    }

    public function testCreateUploadsKeysCreatesALabelledServerAndReturnsTheAction(): void
    {
        $this->api
            ->expect('GET', '/servers', 200, ['servers' => []], ['label_selector' => self::SELECTOR])
            ->expect('GET', '/ssh_keys', 200, ['ssh_keys' => []], ['label_selector' => self::SELECTOR])
            ->expect('POST', '/ssh_keys', 201, ['ssh_key' => ['id' => 501]])
            ->expect('POST', '/servers', 201, [
                'server' => self::server(9001, 'initializing'),
                'action' => ['id' => 7001, 'status' => 'running', 'error' => null],
                'root_password' => null,
            ]);

        $creation = $this->adapter->createServer($this->spec());

        self::assertFalse($creation->adoptedExisting);
        self::assertSame('9001', $creation->server->providerServerId);
        self::assertSame(42, $creation->server->serviceId);
        self::assertSame(VpsServerStatus::Provisioning, $creation->server->status);
        self::assertSame('203.0.113.10', $creation->server->ipv4);
        self::assertSame('7001', $creation->action?->actionId);
        self::assertNull($creation->rootPassword);

        $keyBody = $this->api->sent[2]['request']->json;
        self::assertSame('paxo-svc-42-key1', $keyBody['name'] ?? null);
        $publicKey = $keyBody['public_key'] ?? null;
        self::assertIsString($publicKey);
        self::assertStringStartsWith('ssh-ed25519 ', $publicKey);
        self::assertStringNotContainsString('test@example', $publicKey, 'Customer key comments are not sent to the provider');

        $serverBody = $this->api->sent[3]['request']->json ?? [];
        self::assertSame('paxo-svc-42', $serverBody['name']);
        self::assertSame(['paxo-svc' => '42', 'paxo-env' => 'prod'], $serverBody['labels']);
        self::assertSame([501], $serverBody['ssh_keys']);
        self::assertSame('cx22', $serverBody['server_type']);
        self::assertSame(HetznerCloudAdapter::BASE_URL, $this->api->sent[3]['baseUrl']);
    }

    public function testCreateAdoptsAnExistingServerInsteadOfCreatingADuplicate(): void
    {
        $this->api->expect('GET', '/servers', 200, ['servers' => [self::server(9001, 'running')]], ['label_selector' => self::SELECTOR]);

        $creation = $this->adapter->createServer($this->spec());

        self::assertTrue($creation->adoptedExisting);
        self::assertNull($creation->action);
        self::assertSame(['GET /servers'], $this->api->calls(), 'No POST may be sent when the server already exists');
    }

    public function testRetryAfterATimeoutDoesNotCreateASecondServer(): void
    {
        // Attempt 1: the create request times out after Hetzner accepted it.
        $this->api
            ->expect('GET', '/servers', 200, ['servers' => []])
            ->expect('GET', '/ssh_keys', 200, ['ssh_keys' => []])
            ->expect('POST', '/ssh_keys', 201, ['ssh_key' => ['id' => 501]])
            ->expectFailure('POST', '/servers');

        try {
            $this->adapter->createServer($this->spec());
            self::fail('Expected a retryable error');
        } catch (ProviderError $e) {
            self::assertSame(ProviderErrorClass::Retryable, $e->class);
            self::assertSame('transport_failure', $e->providerCode);
        }

        // Attempt 2 (job retry): the server exists, so it is adopted.
        $this->api->expect('GET', '/servers', 200, ['servers' => [self::server(9001, 'running')]]);

        self::assertTrue($this->adapter->createServer($this->spec())->adoptedExisting);
    }

    public function testConcurrentCreateRaceIsResolvedByAdoption(): void
    {
        $this->api
            ->expect('GET', '/servers', 200, ['servers' => []])
            ->expect('GET', '/ssh_keys', 200, ['ssh_keys' => []])
            ->expect('POST', '/ssh_keys', 201, ['ssh_key' => ['id' => 501]])
            ->expect('POST', '/servers', 409, ['error' => ['code' => 'uniqueness_error', 'message' => 'server name is already used']])
            ->expect('GET', '/servers', 200, ['servers' => [self::server(9001, 'initializing')]]);

        self::assertTrue($this->adapter->createServer($this->spec())->adoptedExisting);
    }

    public function testStaleKeysFromAnInterruptedAttemptAreRemovedBeforeUpload(): void
    {
        $this->api
            ->expect('GET', '/servers', 200, ['servers' => []])
            ->expect('GET', '/ssh_keys', 200, ['ssh_keys' => [['id' => 400], ['id' => 401]]])
            ->expect('DELETE', '/ssh_keys/400', 204)
            ->expect('DELETE', '/ssh_keys/401', 404, ['error' => ['code' => 'not_found', 'message' => '']])
            ->expect('POST', '/ssh_keys', 201, ['ssh_key' => ['id' => 501]])
            ->expect('POST', '/servers', 201, ['server' => self::server(9001, 'initializing'), 'action' => ['id' => 7001, 'status' => 'running']]);

        $this->adapter->createServer($this->spec());
    }

    public function testGeneratedRootPasswordIsReturnedButRedactedFromDebugOutput(): void
    {
        $this->api
            ->expect('GET', '/servers', 200, ['servers' => []])
            ->expect('GET', '/ssh_keys', 200, ['ssh_keys' => []])
            ->expect('POST', '/servers', 201, [
                'server' => self::server(9001, 'initializing'),
                'action' => ['id' => 7001, 'status' => 'running'],
                'root_password' => 'Generated-Root-Pw-Example',
            ]);

        $creation = $this->adapter->createServer(new CreateVpsServer(42, 'cx22', 'fsn1', 'ubuntu-24.04', []));

        self::assertSame('Generated-Root-Pw-Example', $creation->rootPassword);
        self::assertStringNotContainsString('Generated-Root-Pw-Example', print_r($creation, true));
    }

    public function testPowerVerifiesOwnershipThenSendsTheMappedCommand(): void
    {
        $this->api
            ->expect('GET', '/servers/9001', 200, ['server' => self::server(9001, 'running')])
            ->expect('POST', '/servers/9001/actions/reset', 201, ['action' => ['id' => 7002, 'status' => 'running']]);

        $action = $this->adapter->power(new ServerRef(42, '9001'), PowerAction::Reset);

        self::assertSame('7002', $action->actionId);
        self::assertFalse($action->isFinished());
    }

    public function testDeleteRefusesAServerLabelledForAnotherService(): void
    {
        $this->api->expect('GET', '/servers/9001', 200, ['server' => self::server(9001, 'running', serviceId: 77)]);

        $this->expectException(OwnershipMismatch::class);
        try {
            $this->adapter->deleteServer(new ServerRef(42, '9001'));
        } finally {
            self::assertSame(['GET /servers/9001'], $this->api->calls(), 'DELETE must never be sent on a mismatch');
        }
    }

    public function testDeleteRefusesAServerFromAnotherEnvironment(): void
    {
        $this->api->expect('GET', '/servers/9001', 200, ['server' => self::server(9001, 'running', env: 'staging')]);

        $this->expectException(OwnershipMismatch::class);
        $this->adapter->deleteServer(new ServerRef(42, '9001'));
    }

    public function testDeleteRefusesAnUnlabelledServer(): void
    {
        $server = self::server(9001, 'running');
        $server['labels'] = [];
        $this->api->expect('GET', '/servers/9001', 200, ['server' => $server]);

        $this->expectException(OwnershipMismatch::class);
        $this->adapter->deleteServer(new ServerRef(42, '9001'));
    }

    public function testDeleteIsIdempotentWhenTheServerIsAlreadyGone(): void
    {
        $this->api->expect('GET', '/servers/9001', 404, ['error' => ['code' => 'not_found', 'message' => 'server not found']]);

        self::assertNull($this->adapter->deleteServer(new ServerRef(42, '9001')));
    }

    public function testDeleteOfAnOwnedServerReturnsTheAction(): void
    {
        $this->api
            ->expect('GET', '/servers/9001', 200, ['server' => self::server(9001, 'off')])
            ->expect('DELETE', '/servers/9001', 200, ['action' => ['id' => 7003, 'status' => 'running']]);

        self::assertSame('7003', $this->adapter->deleteServer(new ServerRef(42, '9001'))?->actionId);
    }

    public function testNonNumericServerIdIsRejectedBeforeAnyCall(): void
    {
        $this->expectException(OwnershipMismatch::class);

        $this->adapter->power(new ServerRef(42, 'cb676a46-66fd'), PowerAction::Start);
    }

    public function testPollActionMapsStatusesAndErrors(): void
    {
        $this->api
            ->expect('GET', '/actions/7001', 200, ['action' => ['id' => 7001, 'status' => 'success', 'error' => null]])
            ->expect('GET', '/actions/7002', 200, ['action' => ['id' => 7002, 'status' => 'error', 'error' => ['code' => 'action_failed', 'message' => 'x']]])
            ->expect('GET', '/actions/7003', 200, ['action' => ['id' => 7003, 'status' => 'something_new']]);

        self::assertSame(VpsActionStatus::Succeeded, $this->adapter->pollAction('7001')->status);
        $failed = $this->adapter->pollAction('7002');
        self::assertSame(VpsActionStatus::Failed, $failed->status);
        self::assertSame('action_failed', $failed->errorCode);
        self::assertSame(VpsActionStatus::Unknown, $this->adapter->pollAction('7003')->status);
    }

    public function testRateLimitIsRetryableWithRetryAfterFromTheResetHeader(): void
    {
        $this->api->expect('GET', '/actions/7001', 429, ['error' => ['code' => 'rate_limit_exceeded', 'message' => '']], null, ['RateLimit-Reset' => '1700000090']);

        try {
            $this->adapter->pollAction('7001');
            self::fail('Expected ProviderError');
        } catch (ProviderError $e) {
            self::assertSame(ProviderErrorClass::Retryable, $e->class);
            self::assertSame(90, $e->retryAfterSeconds);
        }
    }

    public function testErrorsNeverExposeTheTokenOrTheRawBody(): void
    {
        $this->api->expectRaw('GET', '/actions/7001', 502, '<html>upstream secret-looking-body ' . self::SECRET . '</html>');

        try {
            $this->adapter->pollAction('7001');
            self::fail('Expected ProviderError');
        } catch (ProviderError $e) {
            self::assertSame('http_502', $e->providerCode);
            self::assertSame(ProviderErrorClass::Retryable, $e->class);
            self::assertStringNotContainsString(self::SECRET, $e->getMessage());
            self::assertStringNotContainsString('upstream', $e->getMessage());
            self::assertStringNotContainsString(self::SECRET, $e->getTraceAsString());
        }
    }

    public function testMalformedSuccessResponseIsUnknownNotSilentlyEmpty(): void
    {
        $this->api->expect('GET', '/servers', 200, ['unexpected' => true]);

        try {
            $this->adapter->findServerForService(42);
            self::fail('Expected ProviderError');
        } catch (ProviderError $e) {
            self::assertSame(ProviderErrorClass::Unknown, $e->class);
            self::assertFalse($e->class->isRetryable());
        }
    }

    public function testMoreThanOneServerForAServiceNeedsAnOperator(): void
    {
        $this->api->expect('GET', '/servers', 200, ['servers' => [self::server(9001, 'running'), self::server(9002, 'running')]]);

        $this->expectException(ProviderError::class);
        $this->expectExceptionMessage('duplicate_servers_for_service');
        $this->adapter->findServerForService(42);
    }

    public function testFinishCreationDeletesTemporaryKeys(): void
    {
        $this->api
            ->expect('GET', '/ssh_keys', 200, ['ssh_keys' => [['id' => 501]]], ['label_selector' => self::SELECTOR])
            ->expect('DELETE', '/ssh_keys/501', 204);

        $this->adapter->finishCreation(42);
    }

    public function testRejectsUnknownEnvironment(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new HetznerCloudAdapter($this->api, new ProviderCredential('x', 'y'), 'production');
    }

    private function spec(): CreateVpsServer
    {
        $key = trim((string) file_get_contents(__DIR__ . '/../../../../Fixtures/ssh/ed25519.pub'));

        return new CreateVpsServer(42, 'cx22', 'fsn1', 'ubuntu-24.04', [SshPublicKey::parse($key)]);
    }

    /** @return array<string, mixed> */
    private static function server(int $id, string $status, int $serviceId = 42, string $env = 'prod'): array
    {
        return [
            'id' => $id,
            'name' => 'paxo-svc-' . $serviceId,
            'status' => $status,
            'labels' => ['paxo-svc' => (string) $serviceId, 'paxo-env' => $env],
            'public_net' => [
                'ipv4' => ['ip' => '203.0.113.10'],
                'ipv6' => ['ip' => '2001:db8:1::/64'],
            ],
        ];
    }
}
