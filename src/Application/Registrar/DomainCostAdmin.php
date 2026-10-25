<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Registrar;

use PaxofiCloud\Application\Audit\AuditEvent;
use PaxofiCloud\Application\Audit\AuditEventType;
use PaxofiCloud\Application\Audit\AuditTrail;
use PaxofiCloud\Application\Audit\ClientContext;
use PaxofiCloud\Application\Contracts\Clock;
use PaxofiCloud\Application\Contracts\DomainCostReport;
use PaxofiCloud\Application\Contracts\ProviderSyncRequests;
use PaxofiCloud\Application\Domains\RegistrarCheckHealth;
use PaxofiCloud\Application\Staff\StaffAuthorizer;
use PaxofiCloud\Domain\Registrar\FeedField;
use PaxofiCloud\Domain\Registrar\SkippedTld;
use PaxofiCloud\Domain\Staff\Permission;

/**
 * The admin console's domain costs page (THREAT-MODEL-DOMAIN-REGISTRAR.md
 * DR-12, DR-13). Staff with `catalogue.view` see the newest accepted cost
 * list, the newest quarantined snapshot as a summary only, recent runs and
 * whether a request is waiting. Staff with `catalogue.manage` may ask the
 * provider worker to sync now, at most once per provider per 10 minutes.
 * The web tier never calls the registrar.
 */
final readonly class DomainCostAdmin
{
    public const int REQUEST_INTERVAL_SECONDS = 600;
    public const int RECENT_RUNS = 10;

    /** @param 'live'|'ote'|'sandbox' $environment */
    public function __construct(
        private string $provider,
        private string $environment,
        private StaffAuthorizer $authorizer,
        private DomainCostReport $report,
        private ProviderSyncRequests $requests,
        private Clock $clock,
        private AuditTrail $audit,
        private ?RegistrarCheckHealth $availability = null,
    ) {
    }

    /**
     * @return array{
     *     provider: string, environment: string, can_sync: bool, daily_at: string,
     *     pending_since: ?string, next_request_at: ?string,
     *     latest: ?array<string, int|string|null>, quarantined: ?array<string, int|string|null>,
     *     runs: list<array<string, int|string|null>>,
     *     items: list<array{tld: string, currency: string, register: int, renew: int, transfer: ?int, restore: ?int, min_years: int, max_years: int, status: string}>,
     *     skipped: list<array{name: ?string, reason: string, detail: ?string}>,
     *     feed_fields: list<array{path: string, items: int, kinds: list<string>, min: ?int, max: ?int}>,
     *     availability_check: array{outcome: string, asked: int, answered: int, at: string}|null
     * }
     * @throws \PaxofiCloud\Application\Staff\StaffPermissionDenied
     */
    public function overview(string $staffId, ClientContext $client): array
    {
        $principal = $this->authorizer->authorize($staffId, Permission::CatalogueView, $client);
        $latest = $this->report->latestSnapshot($this->provider, $this->environment, true);
        $newest = $this->report->latestSnapshot($this->provider, $this->environment, false);
        // A quarantined snapshot newer than the accepted one is shown as a summary, never as prices (DR-13).
        $quarantined = $newest !== null && $newest->status === 'quarantined' ? $newest : null;

        $items = [];
        if ($latest !== null) {
            foreach ($this->report->items($latest->id) as $cost) {
                $items[] = [
                    'tld' => $cost->tld->value,
                    'currency' => $cost->register->currency->value,
                    'register' => $cost->register->minor,
                    'renew' => $cost->renew->minor,
                    'transfer' => $cost->transfer?->minor,
                    'restore' => $cost->restore?->minor,
                    'min_years' => $cost->minYears,
                    'max_years' => $cost->maxYears,
                    'status' => $cost->providerStatus,
                ];
            }
        }

        // DR-14: what the newest accepted list could not use, and why.
        $skipped = $latest === null ? [] : array_map(
            static fn (SkippedTld $s): array => ['name' => $s->name, 'reason' => $s->reason, 'detail' => $s->detail],
            $this->report->skipped($latest->id),
        );

        // DR-16: the field paths the registrar sent, never their text values.
        $fields = $latest === null ? [] : array_map(
            static fn (FeedField $f): array => ['path' => $f->path, 'items' => $f->items, 'kinds' => $f->kinds, 'min' => $f->min, 'max' => $f->max],
            $this->report->feedFields($latest->id),
        );

        $pending = $this->requests->pendingSince($this->provider);
        $next = $this->nextRequestAt();

        return [
            'provider' => $this->provider,
            'environment' => $this->environment,
            'can_sync' => $principal->can(Permission::CatalogueManage),
            'daily_at' => DomainCostSync::DAILY_AT . ' WAT',
            'pending_since' => $pending?->format(\DATE_ATOM),
            'next_request_at' => $next !== null && $next > $this->clock->now() ? $next->format(\DATE_ATOM) : null,
            'latest' => $latest === null ? null : self::summary($latest),
            'quarantined' => $quarantined === null ? null : self::summary($quarantined),
            'runs' => array_map(static fn (SyncRun $run): array => [
                'trigger' => $run->trigger,
                'outcome' => $run->outcome,
                'reason' => $run->reason,
                'attempts' => $run->attempts,
                'started_at' => $run->startedAt->format(\DATE_ATOM),
                'finished_at' => $run->finishedAt->format(\DATE_ATOM),
            ], $this->report->recentRuns($this->provider, self::RECENT_RUNS)),
            'items' => $items,
            'skipped' => $skipped,
            'feed_fields' => $fields,
            // DS-13: the outcome of the latest availability check (registrar #1 only).
            'availability_check' => $this->availability?->latest(),
        ];
    }

    /**
     * Queues a sync for the provider worker (DR-12).
     *
     * @throws SyncRequestRefused|\PaxofiCloud\Application\Staff\StaffPermissionDenied
     */
    public function requestSync(string $staffId, ClientContext $client): void
    {
        $this->authorizer->authorize($staffId, Permission::CatalogueManage, $client);
        $now = $this->clock->now();
        $next = $this->nextRequestAt();
        if ($next !== null && $next > $now) {
            $retryAfter = max(1, $next->getTimestamp() - $now->getTimestamp());
            $this->audit->record(AuditEventType::PROVIDER_PRICES_SYNC_REQUEST_REFUSED, AuditEvent::FAILURE, $client, details: [
                'actor' => $staffId,
                'provider' => $this->provider,
                'reason' => SyncRequestRefused::TOO_SOON,
            ]);

            throw new SyncRequestRefused(SyncRequestRefused::TOO_SOON, $retryAfter);
        }

        $this->requests->add($this->provider, $staffId, $now);
        $this->audit->record(AuditEventType::PROVIDER_PRICES_SYNC_REQUESTED, AuditEvent::SUCCESS, $client, details: [
            'actor' => $staffId,
            'provider' => $this->provider,
        ]);
    }

    private function nextRequestAt(): ?\DateTimeImmutable
    {
        return $this->requests->lastRequestedAt($this->provider)?->modify('+' . self::REQUEST_INTERVAL_SECONDS . ' seconds');
    }

    /** @return array<string, int|string|null> */
    private static function summary(CostSnapshotSummary $snapshot): array
    {
        return [
            'status' => $snapshot->status,
            'reason' => $snapshot->quarantineReason,
            'items' => $snapshot->itemCount,
            'refused' => $snapshot->refusedCount,
            'fetched_at' => $snapshot->fetchedAt->format(\DATE_ATOM),
        ];
    }
}
