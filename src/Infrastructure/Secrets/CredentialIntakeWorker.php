<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Secrets;

use Paxofi\Core\Contracts\Logger;
use PaxofiCloud\Application\Audit\AuditEvent;
use PaxofiCloud\Application\Audit\AuditEventType;
use PaxofiCloud\Application\Audit\AuditTrail;
use PaxofiCloud\Application\Audit\ClientContext;
use PaxofiCloud\Application\Contracts\Clock;
use PaxofiCloud\Application\Contracts\CredentialIntake;
use PaxofiCloud\Application\Contracts\EmailOutbox;
use PaxofiCloud\Application\Contracts\StaffDirectory;
use PaxofiCloud\Application\Email\EmailAddress;
use PaxofiCloud\Application\Email\EmailTemplates;
use PaxofiCloud\Domain\Providers\ProviderConnection;
use PaxofiCloud\Domain\Staff\Permission;
use PaxofiCloud\Domain\Staff\StaffRole;

/**
 * The provider worker's half of provider connections (PC-1, PC-5, PC-7):
 * opens each sealed submission, checks it again (a sealed box proves
 * nothing about who wrote it, PC-T8), stores the secret under the KEK and
 * deletes the row. Every stored credential is announced to all active staff
 * managers, so a forged row cannot be stored silently.
 */
final readonly class CredentialIntakeWorker
{
    public const int REVIEW_DAYS = 365;
    private const int BATCH = 10;

    /** @param 'dev'|'staging'|'prod' $environment */
    public function __construct(
        private string $environment,
        private CredentialIntake $intake,
        private SealedBox $box,
        private ProviderCredentialWriter $vault,
        private StaffDirectory $directory,
        private EmailOutbox $outbox,
        private AuditTrail $audit,
        private Clock $clock,
        private Logger $logger,
    ) {
    }

    /** @return int rows handled (stored or rejected) */
    public function runOnce(): int
    {
        $handled = 0;
        foreach ($this->intake->oldest(self::BATCH) as $row) {
            $handled++;
            try {
                $payload = $this->box->open($row['sealed']);
            } catch (\UnexpectedValueException) {
                $this->reject($row, 'not_openable');
                continue;
            }
            try {
                [$credential, $secret, $submittedBy] = $this->validated($row, $payload);
            } catch (\InvalidArgumentException $e) {
                sodium_memzero($payload);
                $this->reject($row, $e->getMessage());
                continue;
            }
            sodium_memzero($payload);
            $this->vault->save($credential, $secret, $this->clock->now()->modify('+' . self::REVIEW_DAYS . ' days'));
            sodium_memzero($secret);
            $this->intake->remove($row['credential_id'], $row['sealed']);
            $this->audit->record(AuditEventType::PROVIDER_CREDENTIAL_STORED, AuditEvent::SUCCESS, ClientContext::ip('0.0.0.0'), details: [
                'subject' => $submittedBy,
                'provider' => $credential->provider,
                'credential_id' => $credential->id,
            ]);
            $this->logger->info('provider_credential.stored', ['credential_id' => $credential->id]);
            $this->alertManagers($credential->provider);
        }

        return $handled;
    }

    /**
     * @param array{credential_id: string, provider: string, sealed: string, submitted_by: string} $row
     * @return array{StoredCredential, string, string}
     * @throws \InvalidArgumentException with a fixed reason code
     */
    private function validated(array $row, #[\SensitiveParameter] string $payload): array
    {
        try {
            $data = json_decode($payload, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('malformed');
        }
        if (!is_array($data) || ($data['v'] ?? null) !== 1) {
            throw new \InvalidArgumentException('malformed');
        }
        $provider = ProviderConnection::tryFrom(is_string($data['provider'] ?? null) ? $data['provider'] : '');
        if ($provider === null || $provider->value !== $row['provider']) {
            throw new \InvalidArgumentException('provider_mismatch');
        }
        $expectedId = $provider->credentialId($this->environment);
        if (($data['credential_id'] ?? null) !== $expectedId || $row['credential_id'] !== $expectedId
            || ($data['environment'] ?? null) !== $this->environment || ($data['access_level'] ?? null) !== $provider->accessLevel()) {
            throw new \InvalidArgumentException('credential_mismatch');
        }
        $secret = $data['secret'] ?? null;
        if (!is_string($secret) || $secret === '' || strlen($secret) > ProviderConnection::SECRET_MAX_BYTES) {
            throw new \InvalidArgumentException('secret_invalid');
        }
        $submittedBy = $data['submitted_by'] ?? null;
        if (!is_string($submittedBy) || !hash_equals($row['submitted_by'], $submittedBy)) {
            throw new \InvalidArgumentException('submitter_mismatch');
        }

        return [new StoredCredential($expectedId, $provider->value, $this->environment, $provider->accessLevel()), $secret, $submittedBy];
    }

    /** @param array{credential_id: string, provider: string, sealed: string, submitted_by: string} $row */
    private function reject(array $row, string $reason): void
    {
        $this->intake->remove($row['credential_id'], $row['sealed']);
        $this->audit->record(AuditEventType::PROVIDER_CREDENTIAL_REJECTED, AuditEvent::FAILURE, ClientContext::ip('0.0.0.0'), details: [
            'credential_id' => $row['credential_id'],
            'reason' => $reason,
        ]);
        $this->logger->warning('provider_credential.rejected', ['credential_id' => $row['credential_id'], 'reason' => $reason]);
    }

    private function alertManagers(string $provider): void
    {
        $managers = array_values(array_filter(
            StaffRole::cases(),
            static fn (StaffRole $r): bool => in_array(Permission::StaffManage, $r->permissions(), true),
        ));
        $time = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        foreach ($this->directory->activeHoldersOf($managers) as $manager) {
            try {
                $this->outbox->enqueue(new EmailAddress($manager['email']), EmailTemplates::PROVIDER_CREDENTIAL_CHANGED, [
                    'name' => $manager['full_name'],
                    'provider' => $provider,
                    'time' => $time,
                ]);
            } catch (\Throwable $e) {
                $this->logger->error('provider_credential.alert_failed', ['provider' => $provider, 'error' => $e::class]);
            }
        }
    }
}
