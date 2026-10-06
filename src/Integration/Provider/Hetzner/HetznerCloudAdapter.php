<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Provider\Hetzner;

use Closure;
use PaxofiCloud\Integration\Provider\Http\MalformedProviderResponse;
use PaxofiCloud\Integration\Provider\Http\ProviderRequest;
use PaxofiCloud\Integration\Provider\Http\ProviderResponse;
use PaxofiCloud\Integration\Provider\Http\ProviderTransport;
use PaxofiCloud\Integration\Provider\Http\TransportFailure;
use PaxofiCloud\Integration\Provider\ProviderCredential;
use PaxofiCloud\Integration\Provider\ProviderError;
use PaxofiCloud\Integration\Provider\ProviderErrorClass;
use PaxofiCloud\Integration\Provider\Vps\CreateVpsServer;
use PaxofiCloud\Integration\Provider\Vps\OwnershipMismatch;
use PaxofiCloud\Integration\Provider\Vps\PowerAction;
use PaxofiCloud\Integration\Provider\Vps\ServerRef;
use PaxofiCloud\Integration\Provider\Vps\VpsAction;
use PaxofiCloud\Integration\Provider\Vps\VpsActionStatus;
use PaxofiCloud\Integration\Provider\Vps\VpsCreation;
use PaxofiCloud\Integration\Provider\Vps\VpsProvider;
use PaxofiCloud\Integration\Provider\Vps\VpsServer;
use PaxofiCloud\Integration\Provider\Vps\VpsServerStatus;

/**
 * Hetzner Cloud API v1 adapter (INT-04, SRS VPS-003/004, PRV-003/005).
 *
 * Bound to one Hetzner project and one environment. Every server it creates
 * is named and labelled `paxo-svc-{serviceId}` / `paxo-svc={serviceId}` and
 * `paxo-env={environment}`; mutating calls on existing servers first verify
 * those labels (threat model D-4/V-07).
 */
final class HetznerCloudAdapter implements VpsProvider
{
    public const string BASE_URL = 'https://api.hetzner.cloud/v1';
    private const string PROVIDER = 'hetzner';
    private const string LABEL_SERVICE = 'paxo-svc';
    private const string LABEL_ENV = 'paxo-env';

    /** @var Closure(): int */
    private readonly Closure $now;

    /** @param (Closure(): int)|null $now */
    public function __construct(
        private readonly ProviderTransport $transport,
        private readonly ProviderCredential $credential,
        private readonly string $environment,
        ?Closure $now = null,
    ) {
        if (preg_match('/^(dev|staging|prod)$/', $environment) !== 1) {
            throw new \InvalidArgumentException('Environment must be dev, staging or prod.');
        }
        $this->now = $now ?? static fn (): int => time();
    }

    public function providerName(): string
    {
        return self::PROVIDER;
    }

    public function createServer(CreateVpsServer $spec): VpsCreation
    {
        $existing = $this->findServerForService($spec->serviceId);
        if ($existing !== null) {
            return new VpsCreation($existing, null, true);
        }

        // Keys left over from an interrupted attempt are replaced, so the
        // server only ever receives the keys in this spec.
        $this->finishCreation($spec->serviceId);
        $keyIds = [];
        foreach ($spec->sshKeys as $index => $key) {
            $created = $this->call(ProviderRequest::post('/ssh_keys', [
                'name' => sprintf('%s-key%d', $spec->serverName(), $index + 1),
                'public_key' => $key->openSsh(),
                'labels' => $this->labels($spec->serviceId),
            ]));
            $keyIds[] = self::requireInt(self::requireObject($created, 'ssh_key'), 'id');
        }

        try {
            $body = $this->call(ProviderRequest::post('/servers', [
                'name' => $spec->serverName(),
                'server_type' => $spec->serverType,
                'location' => $spec->location,
                'image' => $spec->image,
                'ssh_keys' => $keyIds,
                'labels' => $this->labels($spec->serviceId),
                'start_after_create' => true,
                'public_net' => ['enable_ipv4' => true, 'enable_ipv6' => true],
            ]));
        } catch (ProviderError $e) {
            // A concurrent attempt won the race for the unique server name.
            if ($e->providerCode === 'uniqueness_error') {
                $raced = $this->findServerForService($spec->serviceId);
                if ($raced !== null) {
                    return new VpsCreation($raced, null, true);
                }
            }
            throw $e;
        }

        $rootPassword = $body['root_password'] ?? null;

        return new VpsCreation(
            $this->server(self::requireObject($body, 'server')),
            $this->action(self::requireObject($body, 'action')),
            false,
            is_string($rootPassword) && $rootPassword !== '' ? $rootPassword : null,
        );
    }

    public function finishCreation(int $serviceId): void
    {
        $body = $this->call(ProviderRequest::get('/ssh_keys', ['label_selector' => $this->selector($serviceId)]));
        foreach (self::requireList($body, 'ssh_keys') as $key) {
            $this->call(ProviderRequest::delete('/ssh_keys/' . self::requireInt($key, 'id')), allowNotFound: true);
        }
    }

    public function findServerForService(int $serviceId): ?VpsServer
    {
        $body = $this->call(ProviderRequest::get('/servers', ['label_selector' => $this->selector($serviceId)]));
        $servers = self::requireList($body, 'servers');
        if (count($servers) > 1) {
            throw new ProviderError(self::PROVIDER, ProviderErrorClass::Unknown, 'duplicate_servers_for_service');
        }

        return $servers === [] ? null : $this->server($servers[0]);
    }

    public function power(ServerRef $ref, PowerAction $action): VpsAction
    {
        $this->assertOwned($ref);
        $command = match ($action) {
            PowerAction::Start => 'poweron',
            PowerAction::Shutdown => 'shutdown',
            PowerAction::Reboot => 'reboot',
            PowerAction::Reset => 'reset',
        };
        $body = $this->call(ProviderRequest::post('/servers/' . $ref->providerServerId . '/actions/' . $command, []));

        return $this->action(self::requireObject($body, 'action'));
    }

    public function deleteServer(ServerRef $ref): ?VpsAction
    {
        if (!$this->assertOwned($ref, allowMissing: true)) {
            return null;
        }
        $body = $this->call(ProviderRequest::delete('/servers/' . $ref->providerServerId), allowNotFound: true);

        return $body === null ? null : $this->action(self::requireObject($body, 'action'));
    }

    public function pollAction(string $actionId): VpsAction
    {
        if (preg_match('/^[1-9][0-9]{0,18}$/', $actionId) !== 1) {
            throw new \InvalidArgumentException('Hetzner action IDs are positive integers.');
        }
        $body = $this->call(ProviderRequest::get('/actions/' . $actionId));

        return $this->action(self::requireObject($body, 'action'));
    }

    /**
     * Verifies provider-side ownership labels before a mutating call.
     * Returns false only when $allowMissing and the server no longer exists.
     */
    private function assertOwned(ServerRef $ref, bool $allowMissing = false): bool
    {
        if (preg_match('/^[1-9][0-9]{0,18}$/', $ref->providerServerId) !== 1) {
            throw new OwnershipMismatch($ref, 'not a Hetzner server ID');
        }
        $body = $this->call(ProviderRequest::get('/servers/' . $ref->providerServerId), allowNotFound: $allowMissing);
        if ($body === null) {
            return false;
        }

        $labels = self::labelsOf(self::requireObject($body, 'server'));
        if (($labels[self::LABEL_SERVICE] ?? null) !== (string) $ref->serviceId) {
            throw new OwnershipMismatch($ref, 'service label does not match');
        }
        if (($labels[self::LABEL_ENV] ?? null) !== $this->environment) {
            throw new OwnershipMismatch($ref, 'environment label does not match');
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     * @phpstan-return ($allowNotFound is true ? array<string, mixed>|null : array<string, mixed>)
     */
    private function call(ProviderRequest $request, bool $allowNotFound = false): ?array
    {
        try {
            $response = $this->transport->send(self::BASE_URL, $request, $this->credential);
        } catch (TransportFailure $e) {
            throw new ProviderError(self::PROVIDER, ProviderErrorClass::Retryable, 'transport_failure', null, null, $e);
        }

        if ($allowNotFound && $response->status === 404) {
            return null;
        }
        if (!$response->isSuccess()) {
            throw $this->error($response);
        }

        try {
            return $response->json();
        } catch (MalformedProviderResponse $e) {
            throw new ProviderError(self::PROVIDER, ProviderErrorClass::Unknown, 'malformed_response', $response->status, null, $e);
        }
    }

    private function error(ProviderResponse $response): ProviderError
    {
        $code = 'http_' . $response->status;
        try {
            $error = $response->json()['error'] ?? null;
            if (is_array($error) && is_string($error['code'] ?? null) && preg_match('/^[a-z_]{1,64}$/', $error['code']) === 1) {
                $code = $error['code'];
            }
        } catch (MalformedProviderResponse) {
            // Keep the status-based code; never surface the raw body.
        }

        return new ProviderError(
            self::PROVIDER,
            HetznerErrorClassifier::classify($code, $response->status),
            $code,
            $response->status,
            $this->retryAfter($response),
        );
    }

    private function retryAfter(ProviderResponse $response): ?int
    {
        if ($response->status !== 429) {
            return null;
        }
        $reset = $response->header('RateLimit-Reset');
        if ($reset === null || preg_match('/^[0-9]{1,12}$/', $reset) !== 1) {
            return 60;
        }

        return max(1, min(3600, (int) $reset - ($this->now)()));
    }

    /** @return array<string, string> */
    private function labels(int $serviceId): array
    {
        return [self::LABEL_SERVICE => (string) $serviceId, self::LABEL_ENV => $this->environment];
    }

    private function selector(int $serviceId): string
    {
        if ($serviceId < 1) {
            throw new \InvalidArgumentException('Service ID must be positive.');
        }

        return sprintf('%s==%d,%s==%s', self::LABEL_SERVICE, $serviceId, self::LABEL_ENV, $this->environment);
    }

    /** @param array<string, mixed> $server */
    private function server(array $server): VpsServer
    {
        $labels = self::labelsOf($server);
        $service = $labels[self::LABEL_SERVICE] ?? null;
        $net = is_array($server['public_net'] ?? null) ? $server['public_net'] : [];
        $ipv4 = is_array($net['ipv4'] ?? null) && is_string($net['ipv4']['ip'] ?? null) ? $net['ipv4']['ip'] : null;
        $ipv6 = is_array($net['ipv6'] ?? null) && is_string($net['ipv6']['ip'] ?? null) ? $net['ipv6']['ip'] : null;

        return new VpsServer(
            (string) self::requireInt($server, 'id'),
            $service !== null && preg_match('/^[1-9][0-9]*$/', $service) === 1 ? (int) $service : null,
            $labels[self::LABEL_ENV] ?? null,
            match (self::requireString($server, 'status')) {
                'initializing' => VpsServerStatus::Provisioning,
                'running' => VpsServerStatus::Running,
                'off' => VpsServerStatus::Off,
                'starting', 'stopping', 'migrating', 'rebuilding' => VpsServerStatus::Transitioning,
                'deleting' => VpsServerStatus::Deleting,
                default => VpsServerStatus::Unknown,
            },
            $ipv4,
            $ipv6,
        );
    }

    /** @param array<string, mixed> $action */
    private function action(array $action): VpsAction
    {
        $error = is_array($action['error'] ?? null) && is_string($action['error']['code'] ?? null) ? $action['error']['code'] : null;

        return new VpsAction(
            (string) self::requireInt($action, 'id'),
            match (self::requireString($action, 'status')) {
                'running' => VpsActionStatus::Running,
                'success' => VpsActionStatus::Succeeded,
                'error' => VpsActionStatus::Failed,
                default => VpsActionStatus::Unknown,
            },
            $error,
        );
    }

    /**
     * @param array<string, mixed> $server
     * @return array<string, string>
     */
    private static function labelsOf(array $server): array
    {
        $labels = [];
        foreach (is_array($server['labels'] ?? null) ? $server['labels'] : [] as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $labels[$key] = $value;
            }
        }

        return $labels;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private static function requireObject(array $body, string $key): array
    {
        $value = $body[$key] ?? null;
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw self::malformed($key);
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @param array<string, mixed> $body
     * @return list<array<string, mixed>>
     */
    private static function requireList(array $body, string $key): array
    {
        $value = $body[$key] ?? null;
        if (!is_array($value) || !array_is_list($value)) {
            throw self::malformed($key);
        }
        $items = [];
        foreach ($value as $item) {
            if (!is_array($item) || ($item !== [] && array_is_list($item))) {
                throw self::malformed($key);
            }
            /** @var array<string, mixed> $item */
            $items[] = $item;
        }

        return $items;
    }

    /** @param array<string, mixed> $data */
    private static function requireInt(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (!is_int($value) || $value < 1) {
            throw self::malformed($key);
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private static function requireString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value)) {
            throw self::malformed($key);
        }

        return $value;
    }

    private static function malformed(string $field): ProviderError
    {
        return new ProviderError(self::PROVIDER, ProviderErrorClass::Unknown, 'malformed_response_' . preg_replace('/[^a-z_]/', '', $field));
    }
}
