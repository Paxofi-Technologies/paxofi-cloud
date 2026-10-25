<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Application\Cart;

use PaxofiCloud\Application\Cart\CartRefused;
use PaxofiCloud\Application\Cart\DomainCart;
use PaxofiCloud\Application\Extensions\ExtensionQuotes;
use PaxofiCloud\Domain\Money\BasisPoints;
use PaxofiCloud\Domain\Pricing\DecimalRate;
use PaxofiCloud\Domain\Pricing\DomainPricingSettings;
use PaxofiCloud\Domain\Pricing\ExchangeRates;
use PaxofiCloud\Domain\Pricing\FxRateVerdict;
use PaxofiCloud\Domain\Pricing\Markup;
use PaxofiCloud\Domain\Pricing\RoundingStep;
use PaxofiCloud\Tests\Support\InMemoryDomainCostStore;
use PaxofiCloud\Tests\Support\InMemoryExtensionSettingsStore;
use PaxofiCloud\Tests\Support\InMemoryFxRateStore;
use PaxofiCloud\Tests\Support\InMemoryRedis;
use PaxofiCloud\Tests\Support\MutableClock;
use PaxofiCloud\Tests\Support\ScriptedPriceFeed;
use PaxofiCloud\Tests\Support\ScriptedRateLimiter;
use PHPUnit\Framework\TestCase;

/** THREAT-MODEL-COMMERCE.md CD-20, C-35 to C-37. Test accounts and names only. */
final class DomainCartTest extends TestCase
{
    private const string ALICE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const string BOB = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private InMemoryRedis $redis;
    private InMemoryExtensionSettingsStore $settings;
    private ScriptedRateLimiter $limiter;
    /** @var array<string, int> */
    private array $costs = ['com' => 1000, 'ng' => 900];

    protected function setUp(): void
    {
        $this->redis = new InMemoryRedis();
        $this->limiter = new ScriptedRateLimiter();
        $this->settings = new InMemoryExtensionSettingsStore();
        $this->settings->pricing = new DomainPricingSettings(new Markup(1000), new BasisPoints(1000), new RoundingStep(1), new RoundingStep(5000));
    }

    /** A new cart per call, like a new request: prices are re-quoted from the current costs. */
    private function cart(): DomainCart
    {
        $costs = new InMemoryDomainCostStore();
        $costs->saveSnapshot('domainnameapi', ScriptedPriceFeed::list($this->costs), null, new \DateTimeImmutable('2026-10-25T02:30:00Z'));
        $rates = new InMemoryFxRateStore();
        $rates->record(new ExchangeRates(['USD' => new DecimalRate('1'), 'NGN' => new DecimalRate('1500'), 'EUR' => new DecimalRate('0.9')], 'test', new \DateTimeImmutable()), FxRateVerdict::Accepted, new \DateTimeImmutable('2026-10-25T01:00:00Z'));
        $quotes = new ExtensionQuotes(['domainnameapi' => 'live'], $costs, $this->settings, $rates, new MutableClock(new \DateTimeImmutable('2026-10-25T12:00:00Z')));

        return new DomainCart($this->redis, $quotes, $this->limiter);
    }

    public function testAddsDomainsOnceAndQuotesThemFreshOnEveryRead(): void
    {
        $this->cart()->addDomain(self::ALICE, 'Paxofi.NG');
        $this->cart()->addDomain(self::ALICE, 'paxofi.com');
        $this->cart()->addDomain(self::ALICE, 'paxofi.ng');

        $view = $this->cart()->view(self::ALICE);
        self::assertSame(['paxofi.ng', 'paxofi.com'], array_column($view['lines'], 'domain'), 'lowercased; a name is not added twice');
        self::assertSame(['usd' => 990, 'ngn' => 1_485_000], $view['lines'][0]['register'], '10% markup; NGN at 1500, rounded to NGN 50');
        self::assertSame(['usd' => 2090, 'ngn' => 3_135_000], $view['total']);

        $stored = (string) $this->redis->get(DomainCart::key(self::ALICE));
        self::assertStringNotContainsString('usd', $stored, 'no price is stored in the cart (CD-3)');

        // C-36: a cost rise shows on the next read; the cart is only a preview.
        $this->costs['com'] = 2000;
        self::assertSame(2200, $this->cart()->view(self::ALICE)['lines'][1]['register']['usd'] ?? null);
    }

    public function testALineWhoseExtensionStopsSellingIsShownButNotTotalled(): void
    {
        $this->cart()->addDomain(self::ALICE, 'paxofi.ng');
        $this->cart()->addDomain(self::ALICE, 'paxofi.com');
        unset($this->costs['ng']);

        $view = $this->cart()->view(self::ALICE);
        self::assertFalse($view['lines'][0]['available']);
        self::assertNull($view['lines'][0]['register']);
        self::assertSame(1100, $view['total']['usd']);
    }

    public function testCartsBelongToTheirAccountOnly(): void
    {
        $this->cart()->addDomain(self::ALICE, 'paxofi.ng');
        $lineId = $this->cart()->view(self::ALICE)['lines'][0]['id'];

        self::assertSame([], $this->cart()->view(self::BOB)['lines'], 'C-35: another account sees nothing');
        try {
            $this->cart()->remove(self::BOB, $lineId);
            self::fail('expected line_not_found');
        } catch (CartRefused $e) {
            self::assertSame(CartRefused::LINE_NOT_FOUND, $e->reason);
        }
        self::assertCount(1, $this->cart()->view(self::ALICE)['lines'], "Alice's line is untouched");

        $this->cart()->remove(self::ALICE, $lineId);
        self::assertNull($this->redis->get(DomainCart::key(self::ALICE)), 'an empty cart is deleted');
    }

    public function testRefusesBadNamesUnsoldExtensionsAFullCartAndFloods(): void
    {
        foreach (['', 'nodot', '-bad.com', 'a..com', str_repeat('a', 64) . '.com', ['x'], 42, null] as $bad) {
            try {
                $this->cart()->addDomain(self::ALICE, $bad);
                self::fail('expected invalid_name');
            } catch (CartRefused $e) {
                self::assertSame(CartRefused::INVALID_NAME, $e->reason);
            }
        }
        try {
            $this->cart()->addDomain(self::ALICE, 'paxofi.xyz');
            self::fail('expected not_for_sale');
        } catch (CartRefused $e) {
            self::assertSame(CartRefused::NOT_FOR_SALE, $e->reason);
        }

        for ($i = 0; $i < DomainCart::MAX_LINES; $i++) {
            $this->cart()->addDomain(self::BOB, 'name' . $i . '.com');
        }
        try {
            $this->cart()->addDomain(self::BOB, 'one-more.com');
            self::fail('expected cart_full');
        } catch (CartRefused $e) {
            self::assertSame(CartRefused::CART_FULL, $e->reason);
        }

        $limited = new ScriptedRateLimiter();
        $limited->hits['cart:add:' . hash('sha256', self::ALICE)] = DomainCart::ADDS_PER_HOUR;
        $this->limiter = $limited;
        try {
            $this->cart()->addDomain(self::ALICE, 'paxofi.com');
            self::fail('expected too_many_requests');
        } catch (CartRefused $e) {
            self::assertSame(CartRefused::TOO_MANY, $e->reason);
            self::assertSame(3600, $e->retryAfter);
        }
    }

    public function testTamperedStoredLinesAreDropped(): void
    {
        $this->redis->setEx(DomainCart::key(self::ALICE), 60, (string) json_encode([
            ['id' => '0123456789abcdef', 'domain' => 'paxofi.ng'],
            ['id' => 'not-an-id', 'domain' => 'paxofi.com'],
            ['id' => 'fedcba9876543210', 'domain' => '<script>.com'],
            'junk',
        ]));

        self::assertSame(['paxofi.ng'], array_column($this->cart()->view(self::ALICE)['lines'], 'domain'));
    }
}
