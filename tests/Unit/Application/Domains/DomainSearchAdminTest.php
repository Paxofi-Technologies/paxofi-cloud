<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Application\Domains;

use PaxofiCloud\Application\Audit\AuditEventType;
use PaxofiCloud\Application\Domains\DomainSearchAdmin;
use PaxofiCloud\Application\Domains\DomainSearchChangeRefused;
use PaxofiCloud\Application\Domains\LookupSettings;
use PaxofiCloud\Application\Extensions\ExtensionQuotes;
use PaxofiCloud\Application\Extensions\ExtensionSettings;
use PaxofiCloud\Application\Staff\StaffAuthorizer;
use PaxofiCloud\Application\Staff\StaffPermissionDenied;
use PaxofiCloud\Domain\Pricing\DecimalRate;
use PaxofiCloud\Domain\Pricing\ExchangeRates;
use PaxofiCloud\Domain\Pricing\FxRateVerdict;
use PaxofiCloud\Domain\Money\BasisPoints;
use PaxofiCloud\Domain\Pricing\DomainPricingSettings;
use PaxofiCloud\Domain\Pricing\Markup;
use PaxofiCloud\Domain\Pricing\RoundingStep;
use PaxofiCloud\Domain\Registrar\TldName;
use PaxofiCloud\Domain\Staff\StaffRole;
use PaxofiCloud\Tests\Support\AuditFixtures;
use PaxofiCloud\Tests\Support\InMemoryAuditLog;
use PaxofiCloud\Tests\Support\InMemoryDomainCostStore;
use PaxofiCloud\Tests\Support\InMemoryDomainSearchSettingsStore;
use PaxofiCloud\Tests\Support\InMemoryExtensionSettingsStore;
use PaxofiCloud\Tests\Support\InMemoryFxRateStore;
use PaxofiCloud\Tests\Support\InMemoryStaffDirectory;
use PaxofiCloud\Tests\Support\MutableClock;
use PaxofiCloud\Tests\Support\ScriptedPriceFeed;
use PaxofiCloud\Tests\Support\ScriptedRateLimiter;
use PHPUnit\Framework\TestCase;

/** The admin Domain search page (THREAT-MODEL-DOMAIN-SEARCH.md DS-21 to DS-23, DS-T13, DS-T19, DS-T22). */
final class DomainSearchAdminTest extends TestCase
{
    private const string CATALOGUE = 'c0000000000000000000000000000000';
    private const string SUPPORT = 'e0000000000000000000000000000000';
    private const string CODE = '123456';

    private InMemoryDomainSearchSettingsStore $store;
    private InMemoryAuditLog $audit;
    private DomainSearchAdmin $admin;

    protected function setUp(): void
    {
        $directory = new InMemoryStaffDirectory();
        $directory->add(self::CATALOGUE, 'active', StaffRole::Catalogue);
        $directory->add(self::SUPPORT, 'active', StaffRole::Support);
        $settings = new InMemoryExtensionSettingsStore();
        $settings->pricing = new DomainPricingSettings(new Markup(2000), new BasisPoints(1000), new RoundingStep(1), new RoundingStep(5000));
        foreach (['ng' => 1, 'com' => 2] as $tld => $order) {
            $settings->save(new ExtensionSettings(new TldName($tld), featuredOrder: $order), 'x', new \DateTimeImmutable());
        }
        $costs = new InMemoryDomainCostStore();
        $costs->saveSnapshot('domainnameapi', ScriptedPriceFeed::list(['com' => 1000, 'ng' => 900, 'com.ng' => 900, 'xyz' => 199]), null, new \DateTimeImmutable('2026-10-25T02:30:00Z'));
        $rates = new InMemoryFxRateStore();
        $rates->record(new ExchangeRates(['USD' => new DecimalRate('1'), 'NGN' => new DecimalRate('1500'), 'EUR' => new DecimalRate('0.9')], 'test', new \DateTimeImmutable()), FxRateVerdict::Accepted, new \DateTimeImmutable('2026-10-25T01:00:00Z'));
        $clock = new MutableClock(new \DateTimeImmutable('2026-10-25T12:00:00Z'));
        $quotes = new ExtensionQuotes(['domainnameapi' => 'live'], $costs, $settings, $rates, $clock);
        $this->store = new InMemoryDomainSearchSettingsStore();
        $this->audit = new InMemoryAuditLog();
        $trail = AuditFixtures::trail($this->audit);
        $this->admin = new DomainSearchAdmin(
            new StaffAuthorizer($directory, $trail),
            $this->store,
            $quotes,
            new ScriptedRateLimiter(),
            static fn (string $staffId, string $code): bool => $code === self::CODE,
            $clock,
            $trail,
        );
    }

    public function testShowsEveryExtensionOnSaleWithTheDefaultSearchSetAndLookups(): void
    {
        $view = $this->admin->overview(self::CATALOGUE, AuditFixtures::client());

        self::assertTrue($view['can_manage']);
        self::assertFalse($view['chosen']);
        self::assertSame(['ng', 'com', 'com.ng'], $view['settings']['search_tlds'], 'featured plus ng and com.ng until staff choose');
        self::assertSame(20, $view['settings']['max_ideas']);
        self::assertSame(['com', 'com.ng', 'ng', 'xyz'], array_column($view['extensions'], 'tld'));
        self::assertSame(['auto'], array_values(array_unique(array_column($view['extensions'], 'mode'))));
    }

    public function testSavesSearchExtensionsAndIdeasWithAnAudit(): void
    {
        $this->admin->saveSettings(self::CATALOGUE, AuditFixtures::client(), ['search_tlds' => ['.XYZ', 'com'], 'max_ideas' => 10, 'prefixes' => ['go', 'x'], 'suffixes' => ['hq']]);

        self::assertSame(['xyz', 'com'], $this->store->settings()->searchTlds);
        self::assertSame(['go'], $this->store->settings()->prefixes, 'DS-T22: words outside the rule are dropped');
        self::assertSame(10, $this->store->settings()->maxIdeas);
        self::assertSame([AuditEventType::DOMAIN_SEARCH_SETTINGS_CHANGED], $this->audit->types());
        self::assertSame('xyz_com', $this->audit->events[0]->details['search_after'] ?? null);
        self::assertTrue($this->admin->overview(self::CATALOGUE, AuditFixtures::client())['chosen']);
    }

    public function testRefusesExtensionsNotOnSaleAndBadInput(): void
    {
        foreach ([
            [['search_tlds' => ['museum'], 'max_ideas' => 10, 'prefixes' => [], 'suffixes' => []], DomainSearchChangeRefused::NOT_ON_SALE],
            [['search_tlds' => [], 'max_ideas' => 31, 'prefixes' => [], 'suffixes' => []], DomainSearchChangeRefused::INVALID],
            [['search_tlds' => ['com', 'com'], 'max_ideas' => 5, 'prefixes' => [], 'suffixes' => []], DomainSearchChangeRefused::INVALID],
            [['search_tlds' => [], 'max_ideas' => 5, 'prefixes' => []], DomainSearchChangeRefused::INVALID],
        ] as [$input, $reason]) {
            try {
                $this->admin->saveSettings(self::CATALOGUE, AuditFixtures::client(), $input);
                self::fail('expected a refusal');
            } catch (DomainSearchChangeRefused $e) {
                self::assertSame($reason, $e->reason);
            }
        }
        self::assertNull($this->store->saved);
    }

    public function testAModeOrPhraseChangeNeedsNoCodeButANewWhoisServerDoes(): void
    {
        $this->admin->saveLookup(self::CATALOGUE, AuditFixtures::client(), ['tld' => 'ng', 'mode' => 'whois', 'whois_server' => null, 'whois_phrase' => 'No Object Found']);
        self::assertEquals(new LookupSettings('ng', 'whois', null, 'No Object Found'), $this->store->lookups()['ng']);

        try {
            $this->admin->saveLookup(self::CATALOGUE, AuditFixtures::client(), ['tld' => 'ng', 'mode' => 'whois', 'whois_server' => 'whois.nic.net.ng', 'whois_phrase' => null]);
            self::fail('expected step-up');
        } catch (DomainSearchChangeRefused $e) {
            self::assertSame(DomainSearchChangeRefused::STEP_UP, $e->reason);
        }
        $this->admin->saveLookup(self::CATALOGUE, AuditFixtures::client(), ['tld' => 'ng', 'mode' => 'whois', 'whois_server' => 'WHOIS.nic.net.ng', 'whois_phrase' => null, 'code' => self::CODE]);
        self::assertSame('whois.nic.net.ng', $this->store->lookups()['ng']->whoisServer);
        // The same server again needs no code.
        $this->admin->saveLookup(self::CATALOGUE, AuditFixtures::client(), ['tld' => 'ng', 'mode' => 'auto', 'whois_server' => 'whois.nic.net.ng', 'whois_phrase' => null]);

        self::assertSame([
            AuditEventType::EXTENSION_LOOKUP_CHANGED,
            AuditEventType::DOMAIN_SEARCH_CHANGE_REFUSED,
            AuditEventType::EXTENSION_LOOKUP_CHANGED,
            AuditEventType::EXTENSION_LOOKUP_CHANGED,
        ], $this->audit->types());
        self::assertSame('whois.nic.net.ng', $this->audit->events[2]->details['whois_server_after'] ?? null);
        self::assertSame('none', $this->audit->events[2]->details['whois_server_before'] ?? null);
    }

    public function testRefusesAnIpOrPortAsWhoisServerAndAShortPhrase(): void
    {
        foreach ([
            ['tld' => 'ng', 'mode' => 'whois', 'whois_server' => '10.0.0.1', 'whois_phrase' => null, 'code' => self::CODE],
            ['tld' => 'ng', 'mode' => 'whois', 'whois_server' => 'whois.evil.example:4444', 'whois_phrase' => null, 'code' => self::CODE],
            ['tld' => 'ng', 'mode' => 'auto', 'whois_server' => null, 'whois_phrase' => 'a'],
            ['tld' => 'ng', 'mode' => 'fastest', 'whois_server' => null, 'whois_phrase' => null],
            ['tld' => 'museum', 'mode' => 'auto', 'whois_server' => null, 'whois_phrase' => null],
        ] as $input) {
            try {
                $this->admin->saveLookup(self::CATALOGUE, AuditFixtures::client(), $input);
                self::fail('expected a refusal');
            } catch (DomainSearchChangeRefused $e) {
                self::assertContains($e->reason, [DomainSearchChangeRefused::INVALID, DomainSearchChangeRefused::NOT_ON_SALE]);
            }
        }
        self::assertSame([], $this->store->lookups());
    }

    public function testStaffWithoutCatalogueManageCannotChangeAnything(): void
    {
        $this->expectException(StaffPermissionDenied::class);
        $this->admin->saveSettings(self::SUPPORT, AuditFixtures::client(), ['search_tlds' => [], 'max_ideas' => 0, 'prefixes' => [], 'suffixes' => []]);
    }
}
