<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Domains;

use Paxofi\Core\Contracts\RedisClient;
use PaxofiCloud\Application\Contracts\Clock;
use PaxofiCloud\Application\Contracts\AvailabilityChecker;
use PaxofiCloud\Application\Contracts\DnsProbe;
use PaxofiCloud\Application\Contracts\ExtensionSettingsStore;
use PaxofiCloud\Application\Contracts\FxRateStore;
use PaxofiCloud\Application\Extensions\ExtensionPrices;
use PaxofiCloud\Application\Registrar\RegistrarUnavailable;
use PaxofiCloud\Domain\Registrar\AvailabilityAnswer;
use PaxofiCloud\Domain\Registrar\TldCost;
use PaxofiCloud\Domain\Registrar\TldName;

/**
 * The worker side of the availability check (DS-13, DS-14): takes queued
 * jobs and answers each name from the first conclusive source:
 *  1. DNS: a name with NS records is taken (no call to anyone else);
 *  2. the registrar (DomainNameAPI, one bulk call): authoritative, and the
 *     only source of premium and reserved;
 *  3. port-43 WHOIS for names the registrar did not answer, within a time
 *     budget per run so other worker tasks never wait long.
 * A premium name with a price gets our selling price from the same markup,
 * rounding and floor as everything else. Any failure answers `unknown`,
 * never available. Names are not logged.
 */
final readonly class RegistrarCheckWorker
{
    public const int MAX_JOBS_PER_RUN = 10;
    /** WHOIS time per run (DS-16 allows 5 s per query); the page polls for 15 s. */
    public const int WHOIS_BUDGET_MS = 12_000;

    /** @param \Closure(): ?AvailabilityChecker $checker null while the registrar is not connected */
    public function __construct(
        private RedisClient $redis,
        private \Closure $checker,
        private ExtensionSettingsStore $settings,
        private FxRateStore $rates,
        private ?RegistrarCheckHealth $health = null,
        private ?Clock $clock = null,
        private ?DnsProbe $dns = null,
        private ?WhoisAvailability $whois = null,
    ) {
    }

    /** @return int jobs answered */
    public function runOnce(): int
    {
        $handled = 0;
        $checker = null;
        $loaded = false;
        $whoisDeadline = hrtime(true) + self::WHOIS_BUDGET_MS * 1_000_000;
        while ($handled < self::MAX_JOBS_PER_RUN) {
            $id = $this->redis->popRight(RegistrarCheck::QUEUE);
            if ($id === null) {
                break;
            }
            if (preg_match('/^[a-f0-9]{32}$/', $id) !== 1) {
                continue;
            }
            $raw = $this->redis->get(RegistrarCheck::jobKey($id));
            $this->redis->delete(RegistrarCheck::jobKey($id));
            $domains = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($domains) || !array_is_list($domains) || $domains === [] || count($domains) > RegistrarCheck::MAX_DOMAINS) {
                continue;
            }
            $domains = array_values(array_filter($domains, 'is_string'));
            if (!$loaded) {
                $loaded = true;
                $checker = ($this->checker)();
            }
            $answers = [];
            $outcome = 'not_connected';
            $registered = [];
            foreach ($domains as $domain) {
                if ($this->dns?->hasNameservers($domain) === true) {
                    $registered[$domain] = new AvailabilityAnswer('taken');
                }
            }
            $toRegistrar = array_values(array_diff($domains, array_keys($registered)));
            if ($checker !== null && $toRegistrar !== []) {
                try {
                    $answers = $checker->checkAvailability($toRegistrar);
                    $outcome = 'answered';
                } catch (RegistrarUnavailable $e) {
                    $answers = [];
                    $outcome = $e->reason;
                } catch (\InvalidArgumentException) {
                    $answers = [];
                    $outcome = 'malformed';
                }
            }
            if ($this->health !== null && $this->clock !== null && $toRegistrar !== []) {
                $this->health->record($outcome, count($toRegistrar), count(array_intersect_key($answers, array_flip($toRegistrar))), $this->clock->now());
            }
            $answers = $registered + $answers;
            foreach ($domains as $domain) {
                $current = $answers[$domain] ?? null;
                if ($current !== null && $current->status !== 'unknown') {
                    continue;
                }
                if ($this->whois === null || hrtime(true) >= $whoisDeadline) {
                    continue;
                }
                $status = $this->whois->check($domain);
                if ($status !== 'unknown') {
                    $answers[$domain] = new AvailabilityAnswer($status);
                }
            }
            $results = [];
            foreach ($domains as $domain) {
                $results[] = $this->result($domain, $answers[$domain] ?? AvailabilityAnswer::unknown());
            }
            $this->redis->setEx(RegistrarCheck::resultKey($id), RegistrarCheck::TTL_SECONDS, json_encode($results, JSON_THROW_ON_ERROR));
            $handled++;
        }

        return $handled;
    }

    /** @return array{domain: string, status: string, premium: bool, register: ?array{usd: int, ngn: ?int}} */
    private function result(string $domain, AvailabilityAnswer $answer): array
    {
        $register = null;
        if ($answer->premium && $answer->premiumCost !== null) {
            $pricing = $this->settings->pricing();
            $rates = $this->rates->latestAccepted();
            $tld = explode('.', $domain, 2)[1] ?? '';
            if ($pricing !== null && $rates !== null && $tld !== '') {
                $cost = new TldCost(new TldName($tld), $answer->premiumCost, $answer->premiumCost, null, null, 1, 1, 'premium');
                $prices = ExtensionPrices::for($cost, $pricing, $rates->rates);
                if ($prices->usd['register'] !== null) {
                    $register = ['usd' => $prices->usd['register'], 'ngn' => $prices->ngn['register']];
                }
            }
        }

        return ['domain' => $domain, 'status' => $answer->status, 'premium' => $answer->premium, 'register' => $register];
    }
}
