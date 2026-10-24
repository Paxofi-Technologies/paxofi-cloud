<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Registrar;

use Paxofi\Core\Contracts\Logger;
use PaxofiCloud\Application\Audit\AuditEvent;
use PaxofiCloud\Application\Audit\AuditEventType;
use PaxofiCloud\Application\Audit\AuditTrail;
use PaxofiCloud\Application\Audit\ClientContext;
use PaxofiCloud\Application\Contracts\Clock;
use PaxofiCloud\Application\Contracts\DomainCostStore;
use PaxofiCloud\Application\Contracts\DomainPriceFeed;
use PaxofiCloud\Application\Contracts\ExclusiveLock;
use PaxofiCloud\Application\Contracts\ProviderSyncRequests;
use PaxofiCloud\Application\Email\EmailTemplates;
use PaxofiCloud\Application\Staff\StaffManagerAlerts;
use PaxofiCloud\Domain\Registrar\CostQuarantinePolicy;
use PaxofiCloud\Domain\Registrar\TldCostList;

/**
 * Pulls a registrar's full cost list and stores it as a snapshot (PRC-011;
 * CD-13; THREAT-MODEL-DOMAIN-REGISTRAR.md DR-8 to DR-10). Runs only in the
 * provider worker, which holds the credential.
 *
 * - One run per provider at a time (named lock); a busy lock skips the run.
 * - Retryable failures (rate limited, provider error, transport) get at most
 *   3 retries, 2 s, 8 s and 30 s apart. A refused credential is never retried.
 * - A failed run stores nothing, so the last good prices stay in use.
 * - A plausible list is stored as accepted; an implausible one is stored as
 *   quarantined and changes nothing until staff review it.
 * - Staff managers are emailed when the credential is refused (once per
 *   streak), after the third failure in a row, and on every quarantine.
 * - Scheduled daily at 02:30 WAT (runIfDue), and on request from the admin
 *   console's "Sync now" (runIfRequested, DR-12).
 */
final class DomainCostSync
{
    public const int MAX_ATTEMPTS = 4;
    /** Seconds to wait before retry 1, 2 and 3. */
    public const array BACKOFF = [2, 8, 30];
    public const string DAILY_AT = '02:30';
    public const string SCHEDULE_TIMEZONE = 'Africa/Lagos';
    public const int ALERT_AFTER_FAILURES = 3;

    /** @param \Closure(int): void $sleep */
    public function __construct(
        private readonly DomainPriceFeed $feed,
        private readonly DomainCostStore $store,
        private readonly ExclusiveLock $lock,
        private readonly CostQuarantinePolicy $policy,
        private readonly StaffManagerAlerts $alerts,
        private readonly AuditTrail $audit,
        private readonly Clock $clock,
        private readonly Logger $logger,
        private readonly \Closure $sleep,
        private readonly ProviderSyncRequests $requests,
    ) {
    }

    /** Runs the scheduled sync when today's 02:30 WAT has passed and it has not run since. */
    public function runIfDue(): ?SyncRun
    {
        $now = $this->clock->now();
        $dueAt = self::dueAt($now);
        if ($now < $dueAt) {
            return null;
        }
        $last = $this->store->lastStartedAt($this->feed->provider(), 'schedule');
        if ($last !== null && $last >= $dueAt) {
            return null;
        }

        return $this->run('schedule');
    }

    /**
     * Runs once for every "Sync now" request waiting, however many there are
     * (DR-12). The requests are claimed before the run, so a run that cannot
     * get the lock still uses them up: the run holding it fetches fresh prices anyway.
     */
    public function runIfRequested(): ?SyncRun
    {
        if ($this->requests->claimAll($this->feed->provider(), $this->clock->now()) === 0) {
            return null;
        }

        return $this->run('manual');
    }

    /** Today's scheduled time in WAT, as an absolute instant. */
    public static function dueAt(\DateTimeImmutable $now): \DateTimeImmutable
    {
        $local = $now->setTimezone(new \DateTimeZone(self::SCHEDULE_TIMEZONE));
        [$hour, $minute] = array_map('intval', explode(':', self::DAILY_AT));

        return $local->setTime($hour, $minute);
    }

    /** @return SyncRun|null null when another run holds the lock */
    public function run(string $trigger): ?SyncRun
    {
        $provider = $this->feed->provider();
        $lockName = 'paxoficloud:sync:' . $provider;
        if (!$this->lock->acquire($lockName)) {
            $this->logger->info('provider_prices.sync_skipped', ['provider' => $provider, 'reason' => 'locked']);

            return null;
        }
        try {
            return $this->runLocked($provider, $trigger);
        } finally {
            $this->lock->release($lockName);
        }
    }

    private function runLocked(string $provider, string $trigger): SyncRun
    {
        $startedAt = $this->clock->now();
        $attempts = 0;
        while (true) {
            $attempts++;
            try {
                $list = $this->feed->fetchAll();
                break;
            } catch (RegistrarUnavailable $e) {
                if (!$e->isRetryable() || $attempts >= self::MAX_ATTEMPTS) {
                    return $this->failed($provider, $trigger, $e, $attempts, $startedAt);
                }
                ($this->sleep)(self::BACKOFF[$attempts - 1]);
            }
        }

        return $this->stored($provider, $trigger, $list, $attempts, $startedAt);
    }

    private function stored(string $provider, string $trigger, TldCostList $list, int $attempts, \DateTimeImmutable $startedAt): SyncRun
    {
        $reason = $this->policy->assess($this->store->latestAccepted($provider, $list->environment), $list);
        $fetchedAt = $this->clock->now();
        $snapshotId = $this->store->saveSnapshot($provider, $list, $reason, $fetchedAt);
        $run = new SyncRun($provider, $trigger, $reason === null ? 'accepted' : 'quarantined', null, $snapshotId, $attempts, $startedAt, $this->clock->now());
        $this->store->recordRun($run);

        $details = ['provider' => $provider, 'trigger' => $trigger, 'items' => count($list->items), 'refused' => $list->refusedTotal()];
        if ($reason === null) {
            $this->audit->record(AuditEventType::PROVIDER_PRICES_SYNCED, AuditEvent::SUCCESS, ClientContext::ip('0.0.0.0'), details: $details);
            $this->logger->info('provider_prices.synced', $details);
        } else {
            $this->audit->record(AuditEventType::PROVIDER_PRICES_QUARANTINED, AuditEvent::FAILURE, ClientContext::ip('0.0.0.0'), details: $details + ['reason' => $reason]);
            $this->logger->warning('provider_prices.quarantined', $details + ['reason' => $reason]);
            $this->alert($provider, 'quarantined', $fetchedAt);
        }

        return $run;
    }

    private function failed(string $provider, string $trigger, RegistrarUnavailable $e, int $attempts, \DateTimeImmutable $startedAt): SyncRun
    {
        $previous = $this->store->recentRuns($provider, self::ALERT_AFTER_FAILURES);
        $run = new SyncRun($provider, $trigger, 'failed', $e->reason, null, $attempts, $startedAt, $this->clock->now());
        $this->store->recordRun($run);

        $details = ['provider' => $provider, 'trigger' => $trigger, 'reason' => $e->reason, 'attempts' => $attempts];
        $this->audit->record(AuditEventType::PROVIDER_PRICES_SYNC_FAILED, AuditEvent::FAILURE, ClientContext::ip('0.0.0.0'), details: $details);
        $this->logger->warning('provider_prices.sync_failed', $details);

        $previousCredentialFailure = isset($previous[0]) && $previous[0]->outcome === 'failed'
            && in_array($previous[0]->reason, ['unauthorised', 'credential_missing', 'credential_invalid'], true);
        if ($e->needsNewCredential()) {
            if (!$previousCredentialFailure) {
                $this->alert($provider, 'credential_refused', $run->finishedAt);
            }

            return $run;
        }

        $streak = 1;
        foreach ($previous as $earlier) {
            if ($earlier->outcome !== 'failed') {
                break;
            }
            $streak++;
        }
        if ($streak === self::ALERT_AFTER_FAILURES) {
            $this->alert($provider, 'repeated_failures', $run->finishedAt);
        }

        return $run;
    }

    private function alert(string $provider, string $problem, \DateTimeImmutable $at): void
    {
        $this->alerts->send(EmailTemplates::PRICE_SYNC_PROBLEM, [
            'provider' => $provider,
            'problem' => $problem,
            'time' => $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
        ]);
    }
}
