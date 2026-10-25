<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Registrar;

use PaxofiCloud\Application\Audit\AuditEvent;
use PaxofiCloud\Application\Audit\AuditEventType;
use PaxofiCloud\Application\Audit\AuditTrail;
use PaxofiCloud\Application\Audit\ClientContext;
use PaxofiCloud\Application\Contracts\Clock;
use PaxofiCloud\Application\Contracts\PriceCheckStore;
use PaxofiCloud\Application\Contracts\ProviderCredentialStatus;
use PaxofiCloud\Application\Contracts\ProviderSyncRequests;
use PaxofiCloud\Application\Staff\StaffAuthorizer;
use PaxofiCloud\Domain\Staff\Permission;

/**
 * The Spaceship tab of Domain costs (THREAT-MODEL-DOMAIN-PRICING.md SP-3,
 * SP-7). Staff with `catalogue.view` see the newest price check; staff with
 * `catalogue.manage` may ask the provider worker to run one, at most once per
 * 10 minutes and only once a Spaceship connection exists. The web tier never
 * calls Spaceship and never opens the key.
 */
final readonly class SpaceshipPriceCheckAdmin
{
    public const string PROVIDER = 'spaceship';

    public function __construct(
        private string $credentialId,
        private StaffAuthorizer $authorizer,
        private PriceCheckStore $store,
        private ProviderSyncRequests $requests,
        private ProviderCredentialStatus $credentials,
        private Clock $clock,
        private AuditTrail $audit,
    ) {
    }

    /**
     * @return array{
     *     connected: bool, can_run: bool, pending_since: ?string, next_request_at: ?string, probe_tlds: list<string>,
     *     latest: ?array{checked_at: string, trigger: string, outcome: string, reason: ?string, asked: int, answered: int, priced: int,
     *         items: list<array{tld: string, result: string, register: ?int, currency: ?string, other_priced: list<string>}>}
     * }
     * @throws \PaxofiCloud\Application\Staff\StaffPermissionDenied
     */
    public function overview(string $staffId, ClientContext $client): array
    {
        $principal = $this->authorizer->authorize($staffId, Permission::CatalogueView, $client);
        $latest = $this->store->latest();
        $pending = $this->requests->pendingSince(self::PROVIDER);
        $next = $this->nextRequestAt();

        return [
            'connected' => $this->connected(),
            'can_run' => $principal->can(Permission::CatalogueManage),
            'pending_since' => $pending?->format(\DATE_ATOM),
            'next_request_at' => $next !== null && $next > $this->clock->now() ? $next->format(\DATE_ATOM) : null,
            'probe_tlds' => SpaceshipPriceCheck::PROBE_TLDS,
            'latest' => $latest === null ? null : [
                'checked_at' => $latest->checkedAt->format(\DATE_ATOM),
                'trigger' => $latest->trigger,
                'outcome' => $latest->failure === null ? 'done' : 'failed',
                'reason' => $latest->failure,
                'asked' => $latest->asked,
                'answered' => $latest->answered(),
                'priced' => $latest->priced(),
                'items' => array_map(static fn (PriceCheckItem $item): array => [
                    'tld' => $item->tld->value,
                    'result' => $item->result,
                    'register' => $item->register?->minor,
                    'currency' => $item->register?->currency->value,
                    'other_priced' => $item->otherPriced,
                ], $latest->items),
            ],
        ];
    }

    /**
     * Queues a check for the provider worker.
     *
     * @throws SyncRequestRefused|\PaxofiCloud\Application\Staff\StaffPermissionDenied
     */
    public function requestCheck(string $staffId, ClientContext $client): void
    {
        $this->authorizer->authorize($staffId, Permission::CatalogueManage, $client);
        $now = $this->clock->now();
        $next = $this->nextRequestAt();
        $refusal = match (true) {
            !$this->connected() => [SyncRequestRefused::NOT_CONNECTED, 0],
            $next !== null && $next > $now => [SyncRequestRefused::TOO_SOON, max(1, $next->getTimestamp() - $now->getTimestamp())],
            default => null,
        };
        if ($refusal !== null) {
            $this->audit->record(AuditEventType::PROVIDER_PRICES_SYNC_REQUEST_REFUSED, AuditEvent::FAILURE, $client, details: [
                'actor' => $staffId,
                'provider' => self::PROVIDER,
                'reason' => $refusal[0],
            ]);

            throw new SyncRequestRefused($refusal[0], $refusal[1]);
        }

        $this->requests->add(self::PROVIDER, $staffId, $now);
        $this->audit->record(AuditEventType::PROVIDER_PRICES_SYNC_REQUESTED, AuditEvent::SUCCESS, $client, details: [
            'actor' => $staffId,
            'provider' => self::PROVIDER,
            'kind' => 'price_check',
        ]);
    }

    private function connected(): bool
    {
        return isset($this->credentials->configured()[$this->credentialId]);
    }

    private function nextRequestAt(): ?\DateTimeImmutable
    {
        return $this->requests->lastRequestedAt(self::PROVIDER)?->modify('+' . DomainCostAdmin::REQUEST_INTERVAL_SECONDS . ' seconds');
    }
}
