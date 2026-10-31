<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Application\Domains;

use PaxofiCloud\Application\Contracts\AvailabilityChecker;
use PaxofiCloud\Application\Domains\DomainSearchRefused;
use PaxofiCloud\Application\Domains\RegistrarCheck;
use PaxofiCloud\Application\Domains\RegistrarCheckHealth;
use PaxofiCloud\Application\Domains\RegistrarCheckWorker;
use PaxofiCloud\Application\Contracts\DnsProbe;
use PaxofiCloud\Application\Contracts\WhoisLookup;
use PaxofiCloud\Application\Domains\WhoisAvailability;
use PaxofiCloud\Application\Domains\WhoisServerDirectory;
use PaxofiCloud\Application\Domains\WhoisUnavailable;
use PaxofiCloud\Application\Extensions\ExtensionQuotes;
use PaxofiCloud\Application\Registrar\RegistrarUnavailable;
use PaxofiCloud\Domain\Money\BasisPoints;
use PaxofiCloud\Domain\Pricing\CostCurrency;
use PaxofiCloud\Domain\Pricing\DecimalRate;
use PaxofiCloud\Domain\Pricing\DomainPricingSettings;
use PaxofiCloud\Domain\Pricing\ExchangeRates;
use PaxofiCloud\Domain\Pricing\FxRateVerdict;
use PaxofiCloud\Domain\Pricing\Markup;
use PaxofiCloud\Domain\Pricing\RoundingStep;
use PaxofiCloud\Domain\Registrar\AvailabilityAnswer;
use PaxofiCloud\Domain\Registrar\CostAmount;
use PaxofiCloud\Tests\Support\InMemoryDomainCostStore;
use PaxofiCloud\Tests\Support\InMemoryExtensionSettingsStore;
use PaxofiCloud\Tests\Support\InMemoryFxRateStore;
use PaxofiCloud\Tests\Support\InMemoryRedis;
use PaxofiCloud\Tests\Support\MutableClock;
use PaxofiCloud\Tests\Support\ScriptedPriceFeed;
use PaxofiCloud\Tests\Support\ScriptedRateLimiter;
use PHPUnit\Framework\TestCase;

/** THREAT-MODEL-DOMAIN-SEARCH.md DS-13, DS-14 / DS-T10 to DS-T12, DS-T14. Test numbers only. */
final class RegistrarCheckTest extends TestCase
{
    private InMemoryRedis $redis;
    private InMemoryExtensionSettingsStore $settings;
    private InMemoryFxRateStore $rates;
    /** @var list<list<string>> */
    public array $asked = [];
    /** @var list<string> */
    public array $whoisAsked = [];

    protected function setUp(): void
    {
        $this->redis = new InMemoryRedis();
        $this->settings = new InMemoryExtensionSettingsStore();
        $this->settings->pricing = new DomainPricingSettings(new Markup(2000), new BasisPoints(1000), new RoundingStep(1), new RoundingStep(5000));
        $this->rates = new InMemoryFxRateStore();
        $this->rates->record(new ExchangeRates(['USD' => new DecimalRate('1'), 'NGN' => new DecimalRate('1500'), 'EUR' => new DecimalRate('0.9')], 'test', new \DateTimeImmutable()), FxRateVerdict::Accepted, new \DateTimeImmutable('2026-10-25T01:00:00Z'));
    }

    private function web(?ScriptedRateLimiter $limiter = null): RegistrarCheck
    {
        $costs = new InMemoryDomainCostStore();
        $costs->saveSnapshot('domainnameapi', ScriptedPriceFeed::list(['com' => 1000, 'ng' => 900, 'com.ng' => 900]), null, new \DateTimeImmutable('2026-10-25T02:30:00Z'));
        $quotes = new ExtensionQuotes(['domainnameapi' => 'live'], $costs, $this->settings, $this->rates, new MutableClock(new \DateTimeImmutable('2026-10-25T12:00:00Z')));

        return new RegistrarCheck($quotes, $this->redis, $limiter ?? new ScriptedRateLimiter());
    }

    /** @param array<string, AvailabilityAnswer>|null $answers null: registrar not connected */
    private function worker(?array $answers, bool $fail = false, ?RegistrarCheckHealth $health = null, ?DnsProbe $dns = null, ?WhoisAvailability $whois = null): RegistrarCheckWorker
    {
        $checker = $answers === null ? null : new class ($this, $answers, $fail) implements AvailabilityChecker {
            /** @param array<string, AvailabilityAnswer> $answers */
            public function __construct(private RegistrarCheckTest $test, private array $answers, private bool $fail)
            {
            }

            public function checkAvailability(array $domains): array
            {
                $this->test->asked[] = $domains;
                if ($this->fail) {
                    throw new RegistrarUnavailable('transport');
                }

                return $this->answers;
            }
        };

        return new RegistrarCheckWorker($this->redis, static fn (): ?AvailabilityChecker => $checker, $this->settings, $this->rates, $health, new MutableClock(new \DateTimeImmutable('2026-10-25T12:00:00Z')), $dns, $whois);
    }

    /** @param list<string> $withNameservers */
    private static function dns(array $withNameservers): DnsProbe
    {
        return new class ($withNameservers) implements DnsProbe {
            /** @param list<string> $names */
            public function __construct(private array $names)
            {
            }

            public function hasNameservers(string $domain): bool
            {
                return in_array($domain, $this->names, true);
            }
        };
    }

    /** @param array<string, string> $replies domain => WHOIS reply */
    private function whois(array $replies): WhoisAvailability
    {
        $lookup = new class ($this, $replies) implements WhoisLookup {
            /** @param array<string, string> $replies */
            public function __construct(private RegistrarCheckTest $test, private array $replies)
            {
            }

            public function query(string $server, string $query): string
            {
                if ($server === WhoisServerDirectory::IANA) {
                    return "whois: whois.nic.test.example\n";
                }
                $this->test->whoisAsked[] = $query;

                return $this->replies[$query] ?? throw new WhoisUnavailable(WhoisUnavailable::TIMEOUT);
            }
        };
        $limiter = new ScriptedRateLimiter();

        return new WhoisAvailability($lookup, new WhoisServerDirectory($lookup, $this->redis, $limiter), $limiter);
    }

    public function testDnsAnswersTakenFirstAndWhoisFillsWhatTheRegistrarDidNotAnswer(): void
    {
        $web = $this->web();
        $id = $web->request('203.0.113.9', ['cars.com', 'paxofi.ng', 'paxofi.com.ng', 'busy.ng']);

        $this->worker(
            ['paxofi.ng' => new AvailabilityAnswer('available')],
            dns: self::dns(['cars.com']),
            whois: $this->whois(["paxofi.com.ng" => "No match for paxofi.com.ng\n", 'busy.ng' => "Domain Name: busy.ng\n"]),
        )->runOnce();

        self::assertSame([['paxofi.ng', 'paxofi.com.ng', 'busy.ng']], $this->asked, 'a name with NS records never reaches the registrar');
        self::assertSame(['paxofi.com.ng', 'busy.ng'], $this->whoisAsked, 'WHOIS only for names the registrar did not answer');
        $statuses = array_column($web->result($id)['results'], 'status', 'domain');
        self::assertSame(['cars.com' => 'taken', 'paxofi.ng' => 'available', 'paxofi.com.ng' => 'available', 'busy.ng' => 'taken'], $statuses);
    }

    public function testWithoutARegistrarWhoisAloneAnswersAndAFailureStaysUnknown(): void
    {
        $web = $this->web();
        $id = $web->request('203.0.113.9', ['paxofi.ng', 'paxofi.com']);

        $this->worker(null, dns: self::dns([]), whois: $this->whois(['paxofi.ng' => "% NOT FOUND\n"]))->runOnce();

        $statuses = array_column($web->result($id)['results'], 'status', 'domain');
        self::assertSame(['paxofi.ng' => 'available', 'paxofi.com' => 'unknown'], $statuses, 'a WHOIS failure is unknown, never available');
    }

    public function testQueuedNamesAreAnsweredByTheWorkerWithPremiumSellingPrices(): void
    {
        $web = $this->web();
        $id = $web->request('203.0.113.9', ['Paxofi.ng', 'cars.com', 'paxofi.com.ng']);
        self::assertSame(['status' => 'pending', 'results' => []], $web->result($id));

        $handled = $this->worker([
            'paxofi.ng' => new AvailabilityAnswer('available'),
            'cars.com' => new AvailabilityAnswer('available', true, CostAmount::ofMinor(CostCurrency::USD, 250000)),
        ])->runOnce();

        self::assertSame(1, $handled);
        self::assertSame([['paxofi.ng', 'cars.com', 'paxofi.com.ng']], $this->asked);
        $result = $web->result($id);
        self::assertSame('done', $result['status']);
        self::assertSame(['domain' => 'paxofi.ng', 'status' => 'available', 'premium' => false, 'register' => null], $result['results'][0]);
        self::assertSame(['domain' => 'cars.com', 'status' => 'available', 'premium' => true, 'register' => ['usd' => 300000, 'ngn' => 450_000_000]], $result['results'][1], 'premium: same markup (20%) on the premium cost');
        self::assertSame('unknown', $result['results'][2]['status'], 'not answered = unknown, never available');
        self::assertNull($this->redis->get(RegistrarCheck::jobKey($id)), 'names removed once answered');
    }

    public function testARegistrarFailureOrMissingConnectionAnswersUnknown(): void
    {
        $web = $this->web();
        $first = $web->request('203.0.113.9', ['paxofi.com']);
        $this->worker([], true)->runOnce();
        self::assertSame('unknown', $web->result($first)['results'][0]['status']);

        $second = $web->request('203.0.113.9', ['paxofi.com']);
        $this->worker(null)->runOnce();
        self::assertSame('unknown', $web->result($second)['results'][0]['status']);
    }

    public function testRefusesBadInputUnsoldExtensionsAndFloodsAndOddIds(): void
    {
        $web = $this->web();
        $tooMany = array_map(static fn (int $i): string => "n{$i}.com", range(1, RegistrarCheck::MAX_DOMAINS + 1));
        $tooManyIdeas = array_map(static fn (int $i): string => "i{$i}.com", range(1, RegistrarCheck::MAX_IDEAS + 1));
        foreach ([[[], []], [$tooMany, []], [['a.com'], $tooManyIdeas], [['-bad.com'], []], [['nodot'], []], [[42], []], [['x' => 'a.com'], []], [['a.com'], ['-bad.com']], [['a.com'], ['x' => 'b.com']]] as [$bad, $ideas]) {
            try {
                $web->request('203.0.113.9', $bad, $ideas);
                self::fail('expected a refusal');
            } catch (DomainSearchRefused $e) {
                self::assertSame(DomainSearchRefused::INVALID_NAME, $e->reason);
            }
        }
        try {
            $web->request('203.0.113.9', ['paxofi.xyz']);
            self::fail('expected unknown_extension');
        } catch (DomainSearchRefused $e) {
            self::assertSame(DomainSearchRefused::UNKNOWN_EXTENSION, $e->reason);
        }
        self::assertSame(0, $this->redis->length(RegistrarCheck::QUEUE), 'nothing queued for refused input');

        $limited = $this->web(new ScriptedRateLimiter());
        for ($i = 0; $i < RegistrarCheck::IP_PER_MINUTE; $i++) {
            $limited->request('198.51.100.7', ['paxofi.com']);
        }
        try {
            $limited->request('198.51.100.7', ['paxofi.com']);
            self::fail('expected too_many_requests');
        } catch (DomainSearchRefused $e) {
            self::assertSame(DomainSearchRefused::TOO_MANY, $e->reason);
        }

        $this->expectExceptionObject(new DomainSearchRefused(DomainSearchRefused::INVALID_NAME));
        $web->result('../../etc');
    }

    public function testIdeasAreCheckedWithoutTheRegistrarAndAfterTheSearchedNames(): void
    {
        $web = $this->web();
        $id = $web->request('203.0.113.9', ['paxofi.ng', 'paxofi.com'], ['getpaxofi.com', 'paxofihq.ng', 'paxofi.ng']);

        $this->worker(
            ['paxofi.ng' => new AvailabilityAnswer('available'), 'paxofi.com' => new AvailabilityAnswer('taken')],
            dns: self::dns(['paxofihq.ng']),
            whois: $this->whois(['getpaxofi.com' => "No match for GETPAXOFI.COM\n"]),
        )->runOnce();

        self::assertSame([['paxofi.ng', 'paxofi.com']], $this->asked, 'DS-23: ideas never go to the registrar');
        $statuses = array_column($web->result($id)['results'], 'status', 'domain');
        self::assertSame(['paxofi.ng' => 'available', 'paxofi.com' => 'taken', 'getpaxofi.com' => 'available', 'paxofihq.ng' => 'taken'], $statuses, 'a repeated name is checked once');
    }

    public function testTheRegistrarIsAskedInBatchesOfSix(): void
    {
        $web = $this->web();
        $names = array_map(static fn (int $i): string => "n{$i}.com", range(1, 13));
        $web->request('203.0.113.9', $names);

        $this->worker([])->runOnce();

        self::assertSame([6, 6, 1], array_map('count', $this->asked));
    }

    public function testAnExpiredOrUnknownJobSaysExpired(): void
    {
        self::assertSame(['status' => 'expired', 'results' => []], $this->web()->result(str_repeat('a', 32)));
    }

    public function testTheLatestOutcomeIsRecordedForStaffWithoutNames(): void
    {
        $health = new RegistrarCheckHealth($this->redis);
        $web = $this->web();
        self::assertNull($health->latest());

        $web->request('203.0.113.9', ['paxofi.ng', 'paxofi.com']);
        $this->worker(['paxofi.ng' => new AvailabilityAnswer('available')], false, $health)->runOnce();
        self::assertSame(['outcome' => 'answered', 'asked' => 2, 'answered' => 1, 'at' => '2026-10-25T12:00:00+00:00'], $health->latest());

        $web->request('203.0.113.9', ['paxofi.ng']);
        $this->worker([], true, $health)->runOnce();
        self::assertSame('transport', $health->latest()['outcome'] ?? null);

        $web->request('203.0.113.9', ['paxofi.ng']);
        $this->worker(null, false, $health)->runOnce();
        self::assertSame('not_connected', $health->latest()['outcome'] ?? null);
        self::assertStringNotContainsString('paxofi', (string) $this->redis->get(RegistrarCheckHealth::KEY), 'no names kept');
    }
}
