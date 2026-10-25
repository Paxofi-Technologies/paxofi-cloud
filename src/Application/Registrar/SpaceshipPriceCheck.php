<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Registrar;

use Paxofi\Core\Contracts\Logger;
use PaxofiCloud\Application\Audit\AuditEvent;
use PaxofiCloud\Application\Audit\AuditEventType;
use PaxofiCloud\Application\Audit\AuditTrail;
use PaxofiCloud\Application\Audit\ClientContext;
use PaxofiCloud\Application\Contracts\Clock;
use PaxofiCloud\Application\Contracts\DomainAvailabilityCheck;
use PaxofiCloud\Application\Contracts\PriceCheckStore;
use PaxofiCloud\Application\Contracts\ProviderSyncRequests;
use PaxofiCloud\Domain\Registrar\TldName;

/**
 * The Spaceship price check (THREAT-MODEL-DOMAIN-PRICING.md SP-3, SP-7):
 * one availability request for an ordinary, random test name in each of 20
 * common extensions, to learn whether Spaceship's API shows prices for names
 * that are not premium. It registers nothing and never feeds the cost list.
 *
 * Runs in the provider worker when the admin console asks for it (the same
 * request queue as "Sync now", DR-12), or from the operator CLI.
 */
final readonly class SpaceshipPriceCheck
{
    public const array PROBE_TLDS = [
        'com', 'net', 'org', 'info', 'biz', 'co', 'io', 'me', 'xyz', 'site',
        'online', 'store', 'tech', 'app', 'dev', 'us', 'uk', 'co.uk', 'africa', 'com.ng',
    ];
    public const int PROBE_TLDS_COUNT = 20;
    public const string NAME_PREFIX = 'paxofi-price-check-';

    /** @param \Closure(): string $randomLabel 16 lower-case hex characters per call */
    public function __construct(
        private DomainAvailabilityCheck $registrar,
        private PriceCheckStore $store,
        private ProviderSyncRequests $requests,
        private AuditTrail $audit,
        private Clock $clock,
        private Logger $logger,
        private \Closure $randomLabel,
    ) {
    }

    /** Runs once for every waiting request from the admin console, however many there are. */
    public function runIfRequested(): ?PriceCheckRun
    {
        if ($this->requests->claimAll($this->registrar->provider(), $this->clock->now()) === 0) {
            return null;
        }

        return $this->run('manual');
    }

    /** @param 'manual'|'cli' $trigger */
    public function run(string $trigger): PriceCheckRun
    {
        $names = [];
        foreach (self::PROBE_TLDS as $tld) {
            $label = ($this->randomLabel)();
            if (preg_match('/^[0-9a-f]{16}$/', $label) !== 1) {
                throw new \LogicException('Test name labels must be 16 lower-case hex characters.');
            }
            $names[self::NAME_PREFIX . $label . '.' . $tld] = $tld;
        }

        $id = bin2hex(random_bytes(16));
        $checkedAt = $this->clock->now();
        try {
            $answers = $this->registrar->check(array_keys($names));
        } catch (RegistrarUnavailable $e) {
            $run = new PriceCheckRun($id, $trigger, $checkedAt, $e->reason, count($names), []);
            $this->store->save($run);
            $details = ['provider' => $this->registrar->provider(), 'trigger' => $trigger, 'reason' => $e->reason];
            $this->audit->record(AuditEventType::PROVIDER_PRICES_CHECK_FAILED, AuditEvent::FAILURE, ClientContext::ip('0.0.0.0'), details: $details);
            $this->logger->warning('provider_prices.check_failed', $details);

            return $run;
        }

        $byName = [];
        foreach ($answers as $answer) {
            $byName[$answer->domain] = $answer;
        }
        $items = [];
        foreach ($names as $name => $tld) {
            $answer = $byName[$name] ?? null;
            $items[] = $answer === null
                ? new PriceCheckItem(new TldName($tld), 'missing', null, [])
                : new PriceCheckItem(new TldName($tld), $answer->result, $answer->register, $answer->otherPriced);
        }

        $run = new PriceCheckRun($id, $trigger, $checkedAt, null, count($names), $items);
        $this->store->save($run);
        $details = [
            'provider' => $this->registrar->provider(),
            'trigger' => $trigger,
            'asked' => $run->asked,
            'answered' => $run->answered(),
            'priced' => $run->priced(),
        ];
        $this->audit->record(AuditEventType::PROVIDER_PRICES_CHECKED, AuditEvent::SUCCESS, ClientContext::ip('0.0.0.0'), details: $details);
        $this->logger->info('provider_prices.checked', $details);

        return $run;
    }
}
