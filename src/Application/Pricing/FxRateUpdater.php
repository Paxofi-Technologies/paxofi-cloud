<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Pricing;

use Paxofi\Core\Contracts\Logger;
use PaxofiCloud\Application\Contracts\Clock;
use PaxofiCloud\Application\Contracts\FxRateSource;
use PaxofiCloud\Application\Contracts\FxRateStore;
use PaxofiCloud\Domain\Pricing\FxRatePolicy;
use PaxofiCloud\Domain\Pricing\FxRateVerdict;

/**
 * Fetches rates, checks them against the last accepted set and records the
 * result (commerce threat model CD-16, C-27, PRC-013). A held set is stored
 * for Finance to look at but never used. Run by the worker once a day.
 */
final readonly class FxRateUpdater
{
    public function __construct(
        private FxRateSource $source,
        private FxRateStore $store,
        private FxRatePolicy $policy,
        private Clock $clock,
        private Logger $logger,
    ) {
    }

    public function update(): FxUpdateOutcome
    {
        try {
            $candidate = $this->source->fetch();
        } catch (FxSourceUnavailable $e) {
            $this->logger->warning('fx.update.unavailable', ['reason' => $e->getMessage()]);

            return FxUpdateOutcome::Unavailable;
        }

        $verdict = $this->policy->verdict($this->store->latestAccepted()?->rates, $candidate);
        $id = $this->store->record($candidate, $verdict, $this->clock->now());
        if ($verdict === FxRateVerdict::Held) {
            $this->logger->warning('fx.update.held', ['rate_set' => $id, 'source' => $candidate->source]);

            return FxUpdateOutcome::Held;
        }
        $this->logger->info('fx.update.accepted', ['rate_set' => $id, 'source' => $candidate->source]);

        return FxUpdateOutcome::Accepted;
    }

    /** True when automatic repricing must pause because no fresh rate has been accepted (CD-16). */
    public function isStale(): bool
    {
        return $this->policy->isStale($this->store->latestAccepted()?->fetchedAt, $this->clock->now());
    }
}
