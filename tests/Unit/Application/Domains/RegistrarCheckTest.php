<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Application\Domains;

use PaxofiCloud\Application\Contracts\AvailabilityChecker;
use PaxofiCloud\Application\Domains\DomainSearchRefused;
use PaxofiCloud\Application\Domains\RegistrarCheck;
use PaxofiCloud\Application\Domains\RegistrarCheckWorker;
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

/** THREAT-MODEL-DOMAIN-SEARCH.md DS-13 / DS-T10 to DS-T12. Test numbers only. */
final class RegistrarCheckTest extends TestCase
{
    private InMemoryRedis $redis;
    private InMemoryExtensionSettingsStore $settings;
    private InMemoryFxRateStore $rates;
    /** @var list<list<string>> */
    public array $asked = [];

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
    private function worker(?array $answers, bool $fail = false): RegistrarCheckWorker
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

        return new RegistrarCheckWorker($this->redis, static fn (): ?AvailabilityChecker => $checker, $this->settings, $this->rates);
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
        foreach ([[], ['a.com', 'b.com', 'c.com', 'd.com', 'e.com', 'f.com', 'g.com'], ['-bad.com'], ['nodot'], [42], ['x' => 'a.com']] as $bad) {
            try {
                $web->request('203.0.113.9', $bad);
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

    public function testAnExpiredOrUnknownJobSaysExpired(): void
    {
        self::assertSame(['status' => 'expired', 'results' => []], $this->web()->result(str_repeat('a', 32)));
    }
}
