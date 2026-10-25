<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Application\Domains;

use PaxofiCloud\Application\Contracts\DomainLookup;
use PaxofiCloud\Application\Contracts\RdapDirectory;
use PaxofiCloud\Application\Domains\DomainSearch;
use PaxofiCloud\Application\Domains\DomainSearchRefused;
use PaxofiCloud\Application\Extensions\ExtensionQuotes;
use PaxofiCloud\Application\Extensions\ExtensionSettings;
use PaxofiCloud\Domain\Money\BasisPoints;
use PaxofiCloud\Domain\Pricing\DecimalRate;
use PaxofiCloud\Domain\Pricing\DomainPricingSettings;
use PaxofiCloud\Domain\Pricing\ExchangeRates;
use PaxofiCloud\Domain\Pricing\FxRateVerdict;
use PaxofiCloud\Domain\Pricing\Markup;
use PaxofiCloud\Domain\Pricing\RoundingStep;
use PaxofiCloud\Domain\Registrar\TldName;
use PaxofiCloud\Tests\Support\InMemoryDomainCostStore;
use PaxofiCloud\Tests\Support\InMemoryExtensionSettingsStore;
use PaxofiCloud\Tests\Support\InMemoryFxRateStore;
use PaxofiCloud\Tests\Support\InMemoryRedis;
use PaxofiCloud\Tests\Support\MutableClock;
use PaxofiCloud\Tests\Support\ScriptedPriceFeed;
use PaxofiCloud\Tests\Support\ScriptedRateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** THREAT-MODEL-DOMAIN-SEARCH.md DS-1 to DS-10 / DS-T1 to DS-T5. Test numbers only. */
final class DomainSearchTest extends TestCase
{
    private InMemoryRedis $redis;
    private ScriptedRateLimiter $limiter;
    private InMemoryExtensionSettingsStore $settings;
    /** @var list<array{string, string}> */
    private array $lookups = [];
    /** @var array<string, 'available'|'taken'|'unknown'> */
    private array $answers = [];

    protected function setUp(): void
    {
        $this->redis = new InMemoryRedis();
        $this->limiter = new ScriptedRateLimiter();
        $this->settings = new InMemoryExtensionSettingsStore();
        $this->settings->pricing = new DomainPricingSettings(new Markup(2500), new BasisPoints(1000), new RoundingStep(1), new RoundingStep(5000));
        foreach (['ng' => 1, 'com.ng' => 2, 'com' => 3, 'org' => 4, 'site' => 5] as $tld => $order) {
            $this->settings->save(new ExtensionSettings(new TldName($tld), featuredOrder: $order, label: $tld === 'site' ? 'hot' : 'none'), 'x', new \DateTimeImmutable());
        }
    }

    private function search(): DomainSearch
    {
        $costs = new InMemoryDomainCostStore();
        $costs->saveSnapshot('domainnameapi', ScriptedPriceFeed::list(['com' => 1000, 'ng' => 900, 'com.ng' => 900, 'org' => 1100, 'site' => 199, 'xyz' => 199]), null, new \DateTimeImmutable('2026-10-25T02:30:00Z'));
        $rates = new InMemoryFxRateStore();
        $rates->record(new ExchangeRates(['USD' => new DecimalRate('1'), 'NGN' => new DecimalRate('1500'), 'EUR' => new DecimalRate('0.9')], 'test', new \DateTimeImmutable()), FxRateVerdict::Accepted, new \DateTimeImmutable('2026-10-25T01:00:00Z'));
        $quotes = new ExtensionQuotes(['domainnameapi' => 'live'], $costs, $this->settings, $rates, new MutableClock(new \DateTimeImmutable('2026-10-25T12:00:00Z')));
        $directory = new class implements RdapDirectory {
            public function serverFor(string $tld): ?string
            {
                return ['com' => 'https://rdap.verisign.com/com/v1', 'ng' => 'https://rdap.nic.net.ng', 'org' => 'https://rdap.org'][$tld] ?? null;
            }
        };
        $lookup = new class ($this) implements DomainLookup {
            public function __construct(private DomainSearchTest $test)
            {
            }

            public function check(string $domain, string $server): string
            {
                return $this->test->answer($domain, $server);
            }
        };

        return new DomainSearch($quotes, $directory, $lookup, $this->redis, $this->limiter);
    }

    /** @return 'available'|'taken'|'unknown' */
    public function answer(string $domain, string $server): string
    {
        $this->lookups[] = [$domain, $server];

        $answer = $this->answers[$domain] ?? 'available';

        return in_array($answer, ['taken', 'unknown'], true) ? $answer : 'available';
    }

    public function testChecksTheRequestedAndFeaturedExtensionsWithSellingPricesOnly(): void
    {
        $this->answers = ['acme.com' => 'taken'];

        $result = $this->search()->search('203.0.113.9', 'ACME', 'xyz');

        self::assertSame('acme', $result['name']);
        self::assertSame(['acme.xyz', 'acme.ng', 'acme.com.ng', 'acme.com', 'acme.org', 'acme.site'], array_column($result['results'], 'domain'));
        $byDomain = array_column($result['results'], null, 'domain');
        self::assertSame('taken', $byDomain['acme.com']['status']);
        self::assertSame('available', $byDomain['acme.ng']['status']);
        self::assertSame('unknown', $byDomain['acme.xyz']['status'], 'no RDAP server: never shown as available');
        self::assertSame('unknown', $byDomain['acme.site']['status']);
        self::assertSame(['usd' => 1250, 'ngn' => 1_875_000], $byDomain['acme.com']['register']);
        self::assertSame('hot', $byDomain['acme.site']['label']);
        self::assertFalse($byDomain['acme.xyz']['featured']);
        self::assertSame(['domain', 'tld', 'status', 'featured', 'label', 'register', 'renew'], array_keys($byDomain['acme.com']), 'DS-9: no cost, registrar or margin');
        self::assertContains(['acme.com.ng', 'https://rdap.nic.net.ng'], $this->lookups, 'second-level names use the top-level RDAP server');
    }

    public function testResultsAreCachedUnderAHashOfTheNameOnly(): void
    {
        $this->search()->search('203.0.113.9', 'acme', null);
        $this->search()->search('203.0.113.9', 'acme', null);

        self::assertCount(4, $this->lookups, 'second search answered from cache');
        foreach (array_keys($this->redis->values) as $key) {
            self::assertStringNotContainsString('acme', $key, 'DS-7: searched names never appear in Redis keys');
            self::assertMatchesRegularExpression('/^pc:domain_search:[0-9a-f]{64}$/', $key);
        }
        self::assertSame(600, $this->redis->ttl['pc:domain_search:' . hash('sha256', 'acme.com')]);
    }

    public function testAcceptsATypedDomainAndRefusesUnknownExtensions(): void
    {
        $result = $this->search()->search('203.0.113.9', 'acme.org', null);
        self::assertSame('acme.org', $result['results'][0]['domain']);

        $this->expectExceptionObject(new DomainSearchRefused(DomainSearchRefused::UNKNOWN_EXTENSION));
        $this->search()->search('203.0.113.9', 'acme', 'museum');
    }

    /** @return iterable<string, array{string}> */
    public static function badNames(): iterable
    {
        yield 'empty' => [''];
        yield 'leading hyphen' => ['-acme'];
        yield 'space' => ['ac me'];
        yield 'url' => ['http://evil'];
        yield 'idn' => ['ẹkọ'];
        yield 'too long' => [str_repeat('a', 64)];
        yield 'internal host' => ['localhost/'];
    }

    #[DataProvider('badNames')]
    public function testRefusesBadNamesBeforeAnyLookup(string $name): void
    {
        try {
            $this->search()->search('203.0.113.9', $name, 'com');
            self::fail('expected DomainSearchRefused');
        } catch (DomainSearchRefused $e) {
            self::assertSame(DomainSearchRefused::INVALID_NAME, $e->reason);
        }
        self::assertSame([], $this->lookups);
    }

    public function testPerIpAndPerServerLimits(): void
    {
        for ($i = 0; $i < DomainSearch::IP_PER_MINUTE; $i++) {
            $this->search()->search('203.0.113.9', 'n' . $i, 'com');
        }
        try {
            $this->search()->search('203.0.113.9', 'one-more', 'com');
            self::fail('expected DomainSearchRefused');
        } catch (DomainSearchRefused $e) {
            self::assertSame(DomainSearchRefused::TOO_MANY, $e->reason);
        }
        self::assertLessThanOrEqual(DomainSearch::SERVER_PER_10_SECONDS, count(array_filter($this->lookups, static fn (array $l): bool => $l[1] === 'https://rdap.nic.net.ng')), 'per-server cap');
    }

    public function testNothingIsOfferedUntilPricesExist(): void
    {
        $this->settings->pricing = null;
        $this->expectExceptionObject(new DomainSearchRefused(DomainSearchRefused::UNKNOWN_EXTENSION));
        $this->search()->search('203.0.113.9', 'acme', 'com');
    }
}
