<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Domains;

use Paxofi\Core\Contracts\RedisClient;
use PaxofiCloud\Application\Contracts\Clock;
use PaxofiCloud\Application\Contracts\AvailabilityChecker;
use PaxofiCloud\Application\Contracts\DnsProbe;
use PaxofiCloud\Application\Contracts\DomainSearchSettingsStore;
use PaxofiCloud\Application\Contracts\ExtensionSettingsStore;
use PaxofiCloud\Application\Contracts\FxRateStore;
use PaxofiCloud\Application\Extensions\ExtensionPrices;
use PaxofiCloud\Application\Registrar\RegistrarUnavailable;
use PaxofiCloud\Domain\Registrar\AvailabilityAnswer;
use PaxofiCloud\Domain\Registrar\TldCost;
use PaxofiCloud\Domain\Registrar\TldName;

/**
 * The worker side of the availability check (DS-13, DS-14, DS-24): takes
 * queued jobs and answers each name from the first conclusive source:
 *  1. DNS: a name with NS records is taken (no call to anyone else);
 *  2. the registrar (DomainNameAPI, bulk calls of 6), searched names only:
 *     authoritative, and the only source of premium and reserved;
 *  3. RDAP, then port-43 WHOIS, for names still unknown, searched names
 *     before name ideas.
 * Staff may limit an extension to one source (DS-14); DNS still runs for
 * every name because it can only ever say taken.
 * Each job has a 14 s budget (the page waits 20 s) and a run stops taking
 * jobs after 15 s, so other worker tasks never wait long. Name ideas are
 * never sent to the registrar; checkout confirms a chosen idea (premium
 * included) with the registrar like any other name.
 * A premium name with a price gets our selling price from the same markup,
 * rounding and floor as everything else. Any failure answers `unknown`,
 * never available. Names are not logged.
 */
final readonly class RegistrarCheckWorker
{
    public const int MAX_JOBS_PER_RUN = 10;
    /** Time for one job; the page polls for 20 s. */
    public const int JOB_BUDGET_MS = 14_000;
    /** A run stops taking new jobs after this. */
    public const int RUN_BUDGET_MS = 15_000;
    /** DomainNameAPI's bulk search takes at most 6 names per call. */
    public const int REGISTRAR_BATCH = 6;

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
        private ?RdapAvailability $rdap = null,
        private ?DomainSearchSettingsStore $lookups = null,
    ) {
    }

    /** @return int jobs answered */
    public function runOnce(): int
    {
        $handled = 0;
        $checker = null;
        $loaded = false;
        $runDeadline = hrtime(true) + self::RUN_BUDGET_MS * 1_000_000;
        while ($handled < self::MAX_JOBS_PER_RUN && hrtime(true) < $runDeadline) {
            $id = $this->redis->popRight(RegistrarCheck::QUEUE);
            if ($id === null) {
                break;
            }
            if (preg_match('/^[a-f0-9]{32}$/', $id) !== 1) {
                continue;
            }
            $raw = $this->redis->get(RegistrarCheck::jobKey($id));
            $this->redis->delete(RegistrarCheck::jobKey($id));
            [$domains, $ideas] = self::decodeJob($raw);
            if ($domains === []) {
                continue;
            }
            $deadline = hrtime(true) + self::JOB_BUDGET_MS * 1_000_000;
            $all = [...$domains, ...$ideas];
            // DS-14: each extension's lookup mode, fixed WHOIS server and phrase (auto when none is set).
            $settings = $this->lookups?->lookups() ?? [];
            $lookup = static function (string $domain) use ($settings): LookupSettings {
                $tld = explode('.', $domain, 2)[1] ?? '';

                return $settings[$tld] ?? new LookupSettings($tld === '' ? 'invalid' : $tld);
            };
            if (!$loaded) {
                $loaded = true;
                $checker = ($this->checker)();
            }
            $answers = [];
            foreach ($all as $domain) {
                if (hrtime(true) < $deadline && $this->dns?->hasNameservers($domain) === true) {
                    $answers[$domain] = new AvailabilityAnswer('taken');
                }
            }
            $toRegistrar = array_values(array_filter(array_diff($domains, array_keys($answers)), static fn (string $d): bool => $lookup($d)->uses('registrar')));
            if ($checker !== null && $toRegistrar !== []) {
                $outcome = 'answered';
                $answered = [];
                foreach (array_chunk($toRegistrar, self::REGISTRAR_BATCH) as $batch) {
                    if (hrtime(true) >= $deadline) {
                        break;
                    }
                    try {
                        $answered += array_intersect_key($checker->checkAvailability($batch), array_flip($batch));
                    } catch (RegistrarUnavailable $e) {
                        $outcome = $e->reason;
                        break;
                    } catch (\InvalidArgumentException) {
                        $outcome = 'malformed';
                        break;
                    }
                }
                if ($this->health !== null && $this->clock !== null) {
                    $this->health->record($outcome, count($toRegistrar), count($answered), $this->clock->now());
                }
                $answers += $answered;
            } elseif ($this->health !== null && $this->clock !== null && $toRegistrar !== []) {
                $this->health->record('not_connected', count($toRegistrar), 0, $this->clock->now());
            }
            foreach (['rdap', 'whois'] as $source) {
                foreach ($all as $domain) {
                    $current = $answers[$domain] ?? null;
                    $settingsFor = $lookup($domain);
                    if (($current !== null && $current->status !== 'unknown') || hrtime(true) >= $deadline || !$settingsFor->uses($source)) {
                        continue;
                    }
                    $status = match ($source) {
                        'rdap' => $this->rdap?->check($domain) ?? 'unknown',
                        'whois' => $this->whois?->check($domain, $settingsFor->whoisServer, $settingsFor->whoisPhrase) ?? 'unknown',
                    };
                    if ($status !== 'unknown') {
                        $answers[$domain] = new AvailabilityAnswer($status);
                    }
                }
            }
            $domains = $all;
            $results = [];
            foreach ($domains as $domain) {
                $results[] = $this->result($domain, $answers[$domain] ?? AvailabilityAnswer::unknown());
            }
            $this->redis->setEx(RegistrarCheck::resultKey($id), RegistrarCheck::TTL_SECONDS, json_encode($results, JSON_THROW_ON_ERROR));
            $handled++;
        }

        return $handled;
    }

    /**
     * A job is {"domains": [...], "ideas": [...]}, or a plain list from checkout (CD-23).
     *
     * @return array{0: list<string>, 1: list<string>} empty names when the job is malformed
     */
    private static function decodeJob(?string $raw): array
    {
        $job = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($job) && array_is_list($job)) {
            $job = ['domains' => $job, 'ideas' => []];
        }
        $domains = is_array($job) ? ($job['domains'] ?? null) : null;
        $ideas = is_array($job) ? ($job['ideas'] ?? []) : null;
        if (!is_array($domains) || !array_is_list($domains) || $domains === [] || count($domains) > RegistrarCheck::MAX_DOMAINS
            || !is_array($ideas) || !array_is_list($ideas) || count($ideas) > RegistrarCheck::MAX_IDEAS) {
            return [[], []];
        }

        return [array_values(array_filter($domains, 'is_string')), array_values(array_filter($ideas, 'is_string'))];
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
