<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Domains;

use Paxofi\Core\Contracts\RedisClient;
use PaxofiCloud\Application\Contracts\Clock;
use PaxofiCloud\Application\Contracts\AvailabilityChecker;
use PaxofiCloud\Application\Contracts\ExtensionSettingsStore;
use PaxofiCloud\Application\Contracts\FxRateStore;
use PaxofiCloud\Application\Extensions\ExtensionPrices;
use PaxofiCloud\Application\Registrar\RegistrarUnavailable;
use PaxofiCloud\Domain\Registrar\AvailabilityAnswer;
use PaxofiCloud\Domain\Registrar\TldCost;
use PaxofiCloud\Domain\Registrar\TldName;

/**
 * The worker side of the registrar availability check (DS-13): takes queued
 * jobs, asks the registrar (DomainNameAPI, registrar #1) and writes back the
 * status, the premium flag and, for a premium name with a price, our selling
 * price from the same markup, rounding and floor as everything else. Any
 * failure answers `unknown`, never available. Names are not logged.
 */
final readonly class RegistrarCheckWorker
{
    public const int MAX_JOBS_PER_RUN = 10;

    /** @param \Closure(): ?AvailabilityChecker $checker null while the registrar is not connected */
    public function __construct(
        private RedisClient $redis,
        private \Closure $checker,
        private ExtensionSettingsStore $settings,
        private FxRateStore $rates,
        private ?RegistrarCheckHealth $health = null,
        private ?Clock $clock = null,
    ) {
    }

    /** @return int jobs answered */
    public function runOnce(): int
    {
        $handled = 0;
        $checker = null;
        $loaded = false;
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
            if ($checker !== null) {
                try {
                    $answers = $checker->checkAvailability($domains);
                    $outcome = 'answered';
                } catch (RegistrarUnavailable $e) {
                    $answers = [];
                    $outcome = $e->reason;
                } catch (\InvalidArgumentException) {
                    $answers = [];
                    $outcome = 'malformed';
                }
            }
            if ($this->health !== null && $this->clock !== null) {
                $this->health->record($outcome, count($domains), count(array_intersect_key($answers, array_flip($domains))), $this->clock->now());
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
