<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Application\Extensions;

use PaxofiCloud\Application\Audit\AuditEventType;
use PaxofiCloud\Application\Domains\DomainSearchSettings;
use PaxofiCloud\Application\Domains\LookupSettings;
use PaxofiCloud\Application\Extensions\CatalogueSettingsTransfer;
use PaxofiCloud\Application\Extensions\ExtensionChangeRefused;
use PaxofiCloud\Application\Extensions\ExtensionSettings;
use PaxofiCloud\Application\Extensions\ExtensionsAdmin;
use PaxofiCloud\Application\Staff\StaffAuthorizer;
use PaxofiCloud\Application\Staff\StaffPermissionDenied;
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
use PaxofiCloud\Tests\Support\ScriptedRateLimiter;
use PHPUnit\Framework\TestCase;

/** Catalogue settings export and import (THREAT-MODEL-DOMAIN-PRICING.md DX-15). */
final class CatalogueSettingsTransferTest extends TestCase
{
    private const string CATALOGUE = 'c0000000000000000000000000000000';
    private const string SUPPORT = 'e0000000000000000000000000000000';
    private const string CODE = '123456';

    private InMemoryStaffDirectory $directory;
    private InMemoryAuditLog $audit;

    protected function setUp(): void
    {
        $this->directory = new InMemoryStaffDirectory();
        $this->directory->add(self::CATALOGUE, 'active', StaffRole::Catalogue);
        $this->directory->add(self::SUPPORT, 'active', StaffRole::Support);
        $this->audit = new InMemoryAuditLog();
    }

    /** @return array{0: CatalogueSettingsTransfer, 1: InMemoryExtensionSettingsStore, 2: InMemoryDomainSearchSettingsStore} */
    private function transfer(): array
    {
        $trail = AuditFixtures::trail($this->audit);
        $clock = new MutableClock(new \DateTimeImmutable('2026-10-31T12:00:00Z'));
        $authorizer = new StaffAuthorizer($this->directory, $trail);
        $verify = static fn (string $staffId, string $code): bool => $code === self::CODE;
        $extensions = new InMemoryExtensionSettingsStore();
        $search = new InMemoryDomainSearchSettingsStore();
        $limiter = new ScriptedRateLimiter();
        $admin = new ExtensionsAdmin(['domainnameapi' => 'live'], $authorizer, new InMemoryDomainCostStore(), $extensions, new InMemoryFxRateStore(), $limiter, $verify, $clock, $trail);

        return [new CatalogueSettingsTransfer($authorizer, $extensions, $search, $admin, $limiter, $verify, $clock, $trail), $extensions, $search];
    }

    public function testAnExportImportsIntoAnEmptyCatalogueUnchanged(): void
    {
        [$from, $extensions, $search] = $this->transfer();
        $extensions->pricing = new DomainPricingSettings(new Markup(2500), new BasisPoints(1000), new RoundingStep(1), new RoundingStep(5000));
        $extensions->save(new ExtensionSettings(new TldName('xyz'), featuredOrder: 4, label: 'promo', category: 'technology'), self::CATALOGUE, new \DateTimeImmutable());
        $extensions->save(new ExtensionSettings(new TldName('ng'), 'domainnameapi', 1, 'hot', 30, 0, 30, 5000), self::CATALOGUE, new \DateTimeImmutable());
        $search->saveSettings(new DomainSearchSettings(['ng', 'com.ng'], 12, ['get'], ['hq']), self::CATALOGUE, new \DateTimeImmutable());
        $search->saveLookup(new LookupSettings('ng', 'whois', 'whois.nic.net.ng', 'No Object Found'), self::CATALOGUE, new \DateTimeImmutable());

        $file = json_decode(json_encode($from->export(self::CATALOGUE, AuditFixtures::client()), JSON_THROW_ON_ERROR), true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($file);
        self::assertSame(CatalogueSettingsTransfer::FORMAT, $file['format']);
        self::assertArrayNotHasKey('costs', $file, 'no costs, rates or credentials in the file');

        [$to, $newExtensions, $newSearch] = $this->transfer();
        $result = $to->import(self::CATALOGUE, AuditFixtures::client(), ['settings' => $file, 'code' => self::CODE]);

        self::assertSame(['pricing' => true, 'extensions_saved' => 2, 'extensions_skipped' => [], 'search' => true, 'lookups_saved' => 1], $result);
        self::assertEquals($extensions->pricing, $newExtensions->pricing);
        self::assertEquals($extensions->all(), $newExtensions->all());
        self::assertEquals($search->settings(), $newSearch->settings());
        self::assertEquals($search->lookups(), $newSearch->lookups());
        self::assertContains(AuditEventType::CATALOGUE_SETTINGS_EXPORTED, $this->audit->types());
        self::assertContains(AuditEventType::CATALOGUE_SETTINGS_IMPORTED, $this->audit->types());
        self::assertContains(AuditEventType::DOMAIN_EXTENSION_CHANGED, $this->audit->types(), 'each extension goes through the normal save and audit');
    }

    public function testImportNeedsAFreshCodeAndSavesNothingWithoutIt(): void
    {
        [$transfer, $extensions] = $this->transfer();
        $file = ['format' => CatalogueSettingsTransfer::FORMAT, 'version' => 1, 'pricing' => ['markup_bp' => 2000, 'min_margin_bp' => 1000, 'usd_step' => 1, 'ngn_step' => 5000], 'extensions' => [], 'search' => null, 'lookups' => []];

        try {
            $transfer->import(self::CATALOGUE, AuditFixtures::client(), ['settings' => $file, 'code' => '000000']);
            self::fail('expected step-up');
        } catch (ExtensionChangeRefused $e) {
            self::assertSame(ExtensionChangeRefused::STEP_UP, $e->reason);
        }
        self::assertNull($extensions->pricing);
        self::assertSame([AuditEventType::CATALOGUE_SETTINGS_IMPORT_REFUSED], $this->audit->types());
    }

    public function testAFileWithAnyInvalidPartSavesNothing(): void
    {
        $good = ['format' => CatalogueSettingsTransfer::FORMAT, 'version' => 1, 'pricing' => ['markup_bp' => 2000, 'min_margin_bp' => 1000, 'usd_step' => 1, 'ngn_step' => 5000], 'extensions' => [], 'search' => null, 'lookups' => []];
        foreach ([
            ['version' => 2] + $good,
            ['format' => 'whmcs'] + $good,
            ['lookups' => [['tld' => 'ng', 'mode' => 'whois', 'whois_server' => '10.0.0.1', 'whois_phrase' => null]]] + $good,
            ['extensions' => [['tld' => 'ng', 'secret' => 'x']]] + $good,
            ['search' => ['search_tlds' => ['ng'], 'max_ideas' => 99, 'prefixes' => [], 'suffixes' => []]] + $good,
            ['costs' => []] + $good,
        ] as $file) {
            [$transfer, $extensions, $search] = $this->transfer();
            try {
                $transfer->import(self::CATALOGUE, AuditFixtures::client(), ['settings' => $file, 'code' => self::CODE]);
                self::fail('expected a refusal');
            } catch (ExtensionChangeRefused $e) {
                self::assertSame(ExtensionChangeRefused::INVALID, $e->reason);
            }
            self::assertNull($extensions->pricing, 'the valid pricing part was not saved either');
            self::assertSame([], $search->lookups());
        }
    }

    public function testBothNeedCatalogueManage(): void
    {
        [$transfer] = $this->transfer();
        $this->expectException(StaffPermissionDenied::class);
        $transfer->export(self::SUPPORT, AuditFixtures::client());
    }
}
