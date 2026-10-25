<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Application\Domains;

use PaxofiCloud\Application\Domains\DomainPriceList;
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
use PaxofiCloud\Tests\Support\MutableClock;
use PaxofiCloud\Tests\Support\ScriptedPriceFeed;
use PaxofiCloud\Tests\Support\ScriptedRateLimiter;
use PHPUnit\Framework\TestCase;

/** THREAT-MODEL-DOMAIN-SEARCH.md DS-11: public price list, selling prices only. Test numbers only. */
final class DomainPriceListTest extends TestCase
{
    private InMemoryExtensionSettingsStore $settings;
    private ScriptedRateLimiter $limiter;

    protected function setUp(): void
    {
        $this->limiter = new ScriptedRateLimiter();
        $this->settings = new InMemoryExtensionSettingsStore();
        foreach (['ng' => 1, 'com' => 2] as $tld => $order) {
            $this->settings->save(new ExtensionSettings(new TldName($tld), featuredOrder: $order, label: $tld === 'com' ? 'sale' : 'none'), 'x', new \DateTimeImmutable());
        }
    }

    private function list(bool $priced = true): DomainPriceList
    {
        if ($priced) {
            $this->settings->pricing = new DomainPricingSettings(new Markup(1000), new BasisPoints(1000), new RoundingStep(1), new RoundingStep(5000));
        }
        $costs = new InMemoryDomainCostStore();
        $costs->saveSnapshot('domainnameapi', ScriptedPriceFeed::list(['com' => 1000, 'ng' => 900, 'org' => 1100, 'xyz' => 199]), null, new \DateTimeImmutable('2026-10-25T02:30:00Z'));
        $rates = new InMemoryFxRateStore();
        $rates->record(new ExchangeRates(['USD' => new DecimalRate('1'), 'NGN' => new DecimalRate('1500'), 'EUR' => new DecimalRate('0.9')], 'test', new \DateTimeImmutable()), FxRateVerdict::Accepted, new \DateTimeImmutable('2026-10-25T01:00:00Z'));

        return new DomainPriceList(new ExtensionQuotes(['domainnameapi' => 'live'], $costs, $this->settings, $rates, new MutableClock(new \DateTimeImmutable('2026-10-25T12:00:00Z'))), $this->limiter);
    }

    public function testFeaturedCardsInOrderThenEveryExtensionAlphabeticalWithSellingPricesOnly(): void
    {
        $view = $this->list()->view('203.0.113.9', '', 1);

        self::assertSame(['ng', 'com'], array_column($view['featured'], 'tld'));
        self::assertSame(['com', 'ng', 'org', 'xyz'], array_column($view['rows'], 'tld'));
        self::assertSame(4, $view['total']);
        $com = $view['rows'][0];
        self::assertSame(['tld', 'featured', 'label', 'register', 'transfer', 'renew'], array_keys($com));
        self::assertSame(['usd' => 1100, 'ngn' => 1_650_000], $com['register']);
        self::assertTrue($com['featured']);
        self::assertSame('sale', $com['label']);
        self::assertStringNotContainsString('domainnameapi', json_encode($view, JSON_THROW_ON_ERROR));
    }

    public function testSearchFiltersAndPagesAreClamped(): void
    {
        $view = $this->list()->view('203.0.113.9', '.or', 9);

        self::assertSame(['org'], array_column($view['rows'], 'tld'));
        self::assertSame(1, $view['page']);
    }

    public function testNothingIsListedBeforePricingSettingsExist(): void
    {
        $view = $this->list(false)->view('203.0.113.9', '', 1);

        self::assertSame([], $view['rows']);
        self::assertSame([], $view['featured']);
    }

    public function testRefusesOddSearchTextAndLimitsEachIp(): void
    {
        $list = $this->list();
        try {
            $list->view('203.0.113.9', 'a/b', 1);
            self::fail('Expected refusal.');
        } catch (DomainSearchRefused $e) {
            self::assertSame(DomainSearchRefused::INVALID_NAME, $e->reason);
        }
        for ($i = 1; $i < DomainPriceList::IP_PER_MINUTE; $i++) {
            $list->view('203.0.113.9', '', 1);
        }
        $this->expectExceptionObject(new DomainSearchRefused(DomainSearchRefused::TOO_MANY, 60));
        $list->view('203.0.113.9', '', 1);
    }
}
