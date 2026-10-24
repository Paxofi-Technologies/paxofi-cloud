<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Providers;

use PaxofiCloud\Application\Audit\AuditEvent;
use PaxofiCloud\Application\Audit\AuditEventType;
use PaxofiCloud\Application\Audit\AuditTrail;
use PaxofiCloud\Application\Audit\ClientContext;
use PaxofiCloud\Application\Contracts\Clock;
use PaxofiCloud\Application\Contracts\CredentialIntake;
use PaxofiCloud\Application\Contracts\CredentialSealer;
use PaxofiCloud\Application\Contracts\ProviderCredentialStatus;
use PaxofiCloud\Application\Contracts\RateLimiter;
use PaxofiCloud\Application\Staff\SignIn\StaffSecondFactor;
use PaxofiCloud\Application\Staff\StaffAuthorizer;
use PaxofiCloud\Domain\Providers\ConnectionField;
use PaxofiCloud\Domain\Providers\InvalidConnectionFields;
use PaxofiCloud\Domain\Providers\ProviderConnection;
use PaxofiCloud\Domain\Staff\Permission;

/**
 * The admin console's provider connections (threat model
 * THREAT-MODEL-PROVIDER-CONNECTIONS.md). Staff can see whether each provider
 * is configured and replace its credential, never read it (PC-2). A
 * submission needs `provider_credentials.manage` and a fresh authenticator
 * code (PC-3); the secret is sealed to the provider worker before it is
 * stored (PC-1), and the worker does the KEK encryption.
 */
final readonly class ProviderConnections
{
    public const int ATTEMPTS_PER_WINDOW = 5;
    public const int WINDOW_SECONDS = 900;

    /** @param 'dev'|'staging'|'prod' $environment */
    public function __construct(
        private string $environment,
        private StaffAuthorizer $authorizer,
        private ?StaffSecondFactor $secondFactor,
        private ?CredentialSealer $sealer,
        private CredentialIntake $intake,
        private ProviderCredentialStatus $status,
        private RateLimiter $limiter,
        private Clock $clock,
        private AuditTrail $audit,
    ) {
    }

    /**
     * Status only: never a secret, a fragment, a length or a hash (PC-2).
     *
     * @return list<array{provider: string, label: string, credential_id: string, fields: list<array{name: string, label: string, secret: bool}>, configured_at: ?string, review_due_on: ?string, pending_since: ?string}>
     */
    public function overview(string $staffId, ClientContext $client): array
    {
        $this->authorizer->authorize($staffId, Permission::ProviderCredentialsManage, $client);
        $configured = $this->status->configured();
        $pending = $this->intake->pending();
        $result = [];
        foreach (ProviderConnection::cases() as $provider) {
            $id = $provider->credentialId($this->environment);
            $result[] = [
                'provider' => $provider->value,
                'label' => $provider->label(),
                'credential_id' => $id,
                'fields' => array_map(static fn (ConnectionField $f): array => ['name' => $f->name, 'label' => $f->label, 'secret' => $f->secret], $provider->fields()),
                'configured_at' => isset($configured[$id]) ? $configured[$id]['updated_at']->format(\DATE_ATOM) : null,
                'review_due_on' => isset($configured[$id]) ? $configured[$id]['review_due_on']->format('Y-m-d') : null,
                'pending_since' => isset($pending[$id]) ? $pending[$id]->format(\DATE_ATOM) : null,
            ];
        }

        return $result;
    }

    /**
     * @param array<array-key, mixed> $fields
     * @throws ProviderConnectionRefused|\PaxofiCloud\Application\Staff\StaffPermissionDenied
     */
    public function submit(string $staffId, string $providerName, #[\SensitiveParameter] array $fields, #[\SensitiveParameter] string $code, ClientContext $client): void
    {
        $this->authorizer->authorize($staffId, Permission::ProviderCredentialsManage, $client);

        $retry = $this->limiter->hit('staff:provider_credentials:' . $staffId, self::ATTEMPTS_PER_WINDOW, self::WINDOW_SECONDS);
        if ($retry > 0) {
            $this->refuse(ProviderConnectionRefused::THROTTLED, $client, $staffId, null, retryAfter: $retry);
        }
        $provider = ProviderConnection::tryFrom($providerName);
        if ($provider === null) {
            $this->refuse(ProviderConnectionRefused::UNKNOWN_PROVIDER, $client, $staffId, null);
        }
        if ($this->secondFactor === null || preg_match('/^\d{6}$/', $code) !== 1 || !$this->secondFactor->verifyCode($staffId, $code)) {
            $this->refuse(ProviderConnectionRefused::STEP_UP, $client, $staffId, $provider->value);
        }
        try {
            $secret = $provider->secretFrom($fields);
        } catch (InvalidConnectionFields $e) {
            $this->refuse(ProviderConnectionRefused::INVALID_FIELDS, $client, $staffId, $provider->value, field: $e->getMessage());
        }
        if ($this->sealer === null) {
            $this->refuse(ProviderConnectionRefused::UNAVAILABLE, $client, $staffId, $provider->value);
        }

        $credentialId = $provider->credentialId($this->environment);
        $payload = json_encode([
            'v' => 1,
            'credential_id' => $credentialId,
            'provider' => $provider->value,
            'environment' => $this->environment,
            'access_level' => $provider->accessLevel(),
            'secret' => $secret,
            'submitted_by' => $staffId,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $sealed = $this->sealer->seal($payload);
        sodium_memzero($payload);
        sodium_memzero($secret);

        $this->intake->replace($credentialId, $provider->value, $sealed, $staffId, $this->clock->now());
        $this->audit->record(AuditEventType::PROVIDER_CREDENTIAL_SUBMITTED, AuditEvent::SUCCESS, $client, details: [
            'subject' => $staffId,
            'provider' => $provider->value,
            'credential_id' => $credentialId,
        ]);
    }

    /** @return never */
    private function refuse(string $reason, ClientContext $client, string $staffId, ?string $provider, ?string $field = null, int $retryAfter = 0): never
    {
        $details = ['subject' => $staffId, 'reason' => $reason];
        if ($provider !== null) {
            $details['provider'] = $provider;
        }
        $this->audit->record(AuditEventType::PROVIDER_CREDENTIAL_REFUSED, AuditEvent::FAILURE, $client, details: $details);

        throw new ProviderConnectionRefused($reason, $field, $retryAfter);
    }
}
