<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Support;

use PaxofiCloud\Application\Contracts\CredentialIntake;
use PaxofiCloud\Domain\Providers\ProviderConnection;

final class InMemoryCredentialIntake implements CredentialIntake
{
    /** @var array<string, array{credential_id: string, provider: string, sealed: string, submitted_by: string, submitted_at: \DateTimeImmutable}> */
    public array $rows = [];

    public function replace(string $credentialId, string $provider, string $sealed, string $submittedBy, \DateTimeImmutable $now): void
    {
        unset($this->rows[$credentialId]);
        $this->rows[$credentialId] = ['credential_id' => $credentialId, 'provider' => $provider, 'sealed' => $sealed, 'submitted_by' => $submittedBy, 'submitted_at' => $now];
    }

    public function pending(): array
    {
        return array_map(static fn (array $r): \DateTimeImmutable => $r['submitted_at'], $this->rows);
    }

    public function oldest(int $limit, bool $paymentGateways = false): array
    {
        $lane = array_filter($this->rows, static fn (array $r): bool => (ProviderConnection::tryFrom($r['provider'])?->isPaymentGateway() ?? false) === $paymentGateways);

        return array_slice(array_values(array_map(static fn (array $r): array => [
            'credential_id' => $r['credential_id'],
            'provider' => $r['provider'],
            'sealed' => $r['sealed'],
            'submitted_by' => $r['submitted_by'],
        ], $lane)), 0, $limit);
    }

    public function remove(string $credentialId, string $sealed): void
    {
        if (($this->rows[$credentialId]['sealed'] ?? null) === $sealed) {
            unset($this->rows[$credentialId]);
        }
    }
}
