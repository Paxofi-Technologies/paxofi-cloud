<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Application\Extensions;

use PaxofiCloud\Application\Audit\AuditEventType;
use PaxofiCloud\Application\Extensions\ExtensionChangeRefused;
use PaxofiCloud\Application\Extensions\ExtensionSettings;
use PaxofiCloud\Application\Extensions\ExtensionsAdmin;
use PaxofiCloud\Application\Staff\StaffAuthorizer;
use PaxofiCloud\Application\Staff\StaffPermissionDenied;
use PaxofiCloud\Domain\Money\BasisPoints;
use PaxofiCloud\Domain\Pricing\DecimalRate;
use PaxofiCloud\Domain\Pricing\DomainPricingSettings;
use PaxofiCloud\Domain\Pricing\ExchangeRates;
use PaxofiCloud\Domain\Pricing\FxRateVerdict;
use PaxofiCloud\Domain\Pricing\Markup;
use PaxofiCloud\Domain\Pricing\RoundingStep;
use PaxofiCloud\Domain\Registrar\TldName;
use PaxofiCloud\Domain\Staff\StaffRole;
use PaxofiCloud\Tests\Support\AuditFixtures;
use PaxofiCloud\Tests\Support\InMemoryAuditLog;
use PaxofiCloud\Tests\Support\InMemoryDomainCostStore;
use PaxofiCloud\Tests\Support\InMemoryExtensionSettingsStore;
use PaxofiCloud\Tests\Support\InMemoryFxRateStore;
use PaxofiCloud\Tests\Support\InMemoryStaffDirectory;
use PaxofiCloud\Tests\Support\MutableClock;
use PaxofiCloud\Tests\Support\ScriptedPriceFeed;
use PaxofiCloud\Tests\Support\ScriptedRateLimiter;
use PHPUnit\Framework\TestCase;

/** The Domain extensions page (THREAT-MODEL-DOMAIN-PRICING.md DX-2 to DX-13 / DX-T8, DX-T9, DX-T10). */
final class ExtensionsAdminTest extends TestCase
{
    private const string CATALOGUE = 'c0000000000000000000000000000000';
    private const string FINANCE = 'f0000000000000000000000000000000';
    private const string SUPPORT = 'e0000000000000000000000000000000';

    private InMemoryDomainCostStore $costs;
    private InMemoryExtensionSettingsStore $settings;
    private InMemoryFxRateStore $rates;
    private InMemoryAuditLog $audit;
    private ScriptedRateLimiter $limiter;
    private ExtensionsAdmin $admin;

    protected function setUp(): void
    {
        $directory = new InMemoryStaffDirectory();
        $directory->add(self::CATALOGUE, 'active', StaffRole::Catalogue);
        $directory->add(self::FINANCE, 'active', StaffRole::Finance);
        $directory->add(self::SUPPORT, 'active', StaffRole::Support);
        $this->costs = new InMemoryDomainCostStore();
        $this->settings = new InMemoryExtensionSettingsStore();
        $this->rates = new InMemoryFxRateStore();
        $this->audit = new InMemoryAuditLog();
        $this->limiter = new ScriptedRateLimiter();
        $clock = new MutableClock(new \DateTimeImmutable('2026-10-25T12:00:00Z'));
        $trail = AuditFixtures::trail($this->audit);
        $this->admin = new ExtensionsAdmin(
            ['domainnameapi' => 'live', 'namecheap' => 'live', 'dynadot' => 'live'],
            new StaffAuthorizer($directory, $trail),
            $this->costs,
            $this->settings,
            $this->rates,
            $this->limiter,
            static fn (string $staffId, string $code): bool => $code === '123456',
            $clock,
            $trail,
        );
        $at = new \DateTimeImmutable('2026-10-25T02:30:00Z');
        $this->costs->saveSnapshot('domainnameapi', ScriptedPriceFeed::list(['com' => 1081, 'ng' => 900]), null, $at);
        $this->costs->saveSnapshot('namecheap', ScriptedPriceFeed::list(['com' => 1000, 'xyz' => 199]), null, $at);
        $this->costs->saveSnapshot('dynadot', ScriptedPriceFeed::list(['com' => 1100]), null, $at);
    }

    private function price(): void
    {
        $this->settings->pricing = new DomainPricingSettings(new Markup(2500), new BasisPoints(1000), new RoundingStep(1), new RoundingStep(5000));
        $this->rates->record(new ExchangeRates(['USD' => new DecimalRate('1'), 'NGN' => new DecimalRate('1500'), 'EUR' => new DecimalRate('0.9')], 'test', new \DateTimeImmutable('2026-10-25T00:00:00Z')), FxRateVerdict::Accepted, new \DateTimeImmutable('2026-10-25T01:00:00Z'));
    }

    public function testListsEveryExtensionWithTheChosenRegistrarAndPrices(): void
    {
        $this->price();
        $this->settings->save(new ExtensionSettings(new TldName('ng'), featuredOrder: 1, label: 'hot'), self::CATALOGUE, new \DateTimeImmutable());

        $view = $this->admin->overview(self::CATALOGUE, AuditFixtures::client());

        self::assertTrue($view['can_manage']);
        self::assertSame(3, $view['total']);
        self::assertSame(['ng', 'com', 'xyz'], array_column($view['rows'], 'tld'), 'featured first, then alphabetical');
        $com = $view['rows'][1];
        self::assertSame('namecheap', $com['chosen_registrar'], 'cheapest healthy offer');
        self::assertSame(['namecheap', 'domainnameapi', 'dynadot'], array_column($com['offers'], 'registrar'));
        self::assertSame(1250, $com['usd']['register'] ?? null);
        self::assertSame(1_875_000, $com['ngn']['register'] ?? null);
        self::assertSame(30, $com['grace_days']);
        self::assertSame('hot', $view['rows'][0]['label']);
        self::assertSame([], $this->audit->types(), 'reading is not audited');
    }

    public function testNothingIsPricedUntilSettingsAndARateExist(): void
    {
        $view = $this->admin->overview(self::CATALOGUE, AuditFixtures::client(), 'com');

        self::assertNull($view['pricing']);
        self::assertNull($view['fx']);
        self::assertSame(['com'], array_column($view['rows'], 'tld'));
        self::assertNull($view['rows'][0]['usd']);
        self::assertSame('namecheap', $view['rows'][0]['chosen_registrar']);
        // Staff still see the chosen registrar's cost before any pricing exists.
        $cost = $view['rows'][0]['cost'] ?? [];
        self::assertIsInt($cost['register'] ?? null);
        self::assertSame(['register', 'renew', 'transfer', 'restore'], array_keys($cost));
    }

    public function testFinanceReadsSupportIsDeniedAndOnlyCatalogueChanges(): void
    {
        self::assertFalse($this->admin->overview(self::FINANCE, AuditFixtures::client())['can_manage']);
        foreach ([
            fn () => $this->admin->overview(self::SUPPORT, AuditFixtures::client()),
            fn () => $this->admin->saveExtension(self::FINANCE, AuditFixtures::client(), ['tld' => 'com', 'label' => 'sale']),
            fn () => $this->admin->savePricing(self::FINANCE, AuditFixtures::client(), ['code' => '123456']),
        ] as $call) {
            try {
                $call();
                self::fail('expected StaffPermissionDenied');
            } catch (StaffPermissionDenied) {
            }
        }
        self::assertSame([], $this->settings->extensions);
    }

    public function testAPinWinsAndIsAuditedWithTheNewState(): void
    {
        $this->admin->saveExtension(self::CATALOGUE, AuditFixtures::client(), ['tld' => 'com', 'pinned_registrar' => 'dynadot', 'featured_order' => 3, 'label' => 'sale', 'grace_days' => 40, 'grace_fee' => 0, 'redemption_days' => 30, 'redemption_fee' => 8000]);

        $row = $this->admin->overview(self::CATALOGUE, AuditFixtures::client(), 'com')['rows'][0];
        self::assertSame('dynadot', $row['chosen_registrar']);
        self::assertSame(40, $row['grace_days']);
        self::assertSame(8000, $row['redemption_fee']);
        self::assertSame([AuditEventType::DOMAIN_EXTENSION_CHANGED], $this->audit->types());
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function badExtensionInput(): iterable
    {
        yield 'unknown registrar' => [['tld' => 'com', 'pinned_registrar' => 'evil']];
        yield 'unknown label' => [['tld' => 'com', 'label' => '<b>']];
        yield 'too many days' => [['tld' => 'com', 'grace_days' => 91]];
        yield 'negative fee' => [['tld' => 'com', 'redemption_fee' => -1]];
        yield 'text number' => [['tld' => 'com', 'grace_days' => '30']];
        yield 'bad extension' => [['tld' => '../com']];
        yield 'extra field' => [['tld' => 'com', 'cost' => 1]];
    }

    /** @param array<string, mixed> $input */
    #[\PHPUnit\Framework\Attributes\DataProvider('badExtensionInput')]
    public function testBadExtensionInputIsRefused(array $input): void
    {
        try {
            $this->admin->saveExtension(self::CATALOGUE, AuditFixtures::client(), $input);
            self::fail('expected ExtensionChangeRefused');
        } catch (ExtensionChangeRefused $e) {
            self::assertSame(ExtensionChangeRefused::INVALID, $e->reason);
        }
        self::assertSame([], $this->settings->extensions);
    }

    public function testPricingNeedsAFreshCodeIsThrottledAndAudited(): void
    {
        $input = ['markup_bp' => 2500, 'min_margin_bp' => 1000, 'usd_step' => 1, 'ngn_step' => 5000];
        try {
            $this->admin->savePricing(self::CATALOGUE, AuditFixtures::client(), $input + ['code' => '000000']);
            self::fail('expected ExtensionChangeRefused');
        } catch (ExtensionChangeRefused $e) {
            self::assertSame(ExtensionChangeRefused::STEP_UP, $e->reason);
        }
        self::assertNull($this->settings->pricing);

        try {
            $this->admin->savePricing(self::CATALOGUE, AuditFixtures::client(), ['markup_bp' => 500, 'min_margin_bp' => 1000, 'usd_step' => 1, 'ngn_step' => 1, 'code' => '123456']);
            self::fail('expected ExtensionChangeRefused');
        } catch (ExtensionChangeRefused $e) {
            self::assertSame(ExtensionChangeRefused::INVALID, $e->reason, 'floor above markup');
        }

        $this->admin->savePricing(self::CATALOGUE, AuditFixtures::client(), $input + ['code' => '123456']);
        self::assertSame(2500, $this->settings->pricing?->markup->basisPoints);
        self::assertSame([AuditEventType::DOMAIN_PRICING_CHANGE_REFUSED, AuditEventType::DOMAIN_PRICING_CHANGED], $this->audit->types());

        for ($i = 0; $i < 2; $i++) { // five attempts in all, then the sixth is refused
            $this->admin->savePricing(self::CATALOGUE, AuditFixtures::client(), $input + ['code' => '123456']);
        }
        $this->expectExceptionObject(new ExtensionChangeRefused(ExtensionChangeRefused::THROTTLED, 900));
        $this->admin->savePricing(self::CATALOGUE, AuditFixtures::client(), $input + ['code' => '123456']);
    }
}
