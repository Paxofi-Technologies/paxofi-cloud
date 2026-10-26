<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Application\Billing;

use PaxofiCloud\Application\Audit\AuditEventType;
use PaxofiCloud\Application\Billing\TaxRateChangeRefused;
use PaxofiCloud\Application\Billing\TaxRatesAdmin;
use PaxofiCloud\Application\Staff\StaffAuthorizer;
use PaxofiCloud\Application\Staff\StaffPermissionDenied;
use PaxofiCloud\Domain\Staff\StaffRole;
use PaxofiCloud\Tests\Support\AuditFixtures;
use PaxofiCloud\Tests\Support\InMemoryAuditLog;
use PaxofiCloud\Tests\Support\InMemoryStaffDirectory;
use PaxofiCloud\Tests\Support\InMemoryTaxRateStore;
use PaxofiCloud\Tests\Support\MutableClock;
use PaxofiCloud\Tests\Support\ScriptedRateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The Tax rates page (commerce threat model CD-28 / C-47, C-48). */
final class TaxRatesAdminTest extends TestCase
{
    private const string CATALOGUE = 'c0000000000000000000000000000000';
    private const string FINANCE = 'f0000000000000000000000000000000';
    private const string SUPPORT = 'e0000000000000000000000000000000';
    private const string CODE = '123456';

    private InMemoryTaxRateStore $rates;
    private InMemoryAuditLog $audit;
    private MutableClock $clock;
    private TaxRatesAdmin $admin;

    protected function setUp(): void
    {
        $directory = new InMemoryStaffDirectory();
        $directory->add(self::CATALOGUE, 'active', StaffRole::Catalogue);
        $directory->add(self::FINANCE, 'active', StaffRole::Finance);
        $directory->add(self::SUPPORT, 'active', StaffRole::Support);
        $this->rates = new InMemoryTaxRateStore();
        $this->audit = new InMemoryAuditLog();
        $this->clock = new MutableClock(new \DateTimeImmutable('2026-10-25T12:00:00Z'));
        $trail = AuditFixtures::trail($this->audit);
        $this->admin = new TaxRatesAdmin(
            new StaffAuthorizer($directory, $trail),
            $this->rates,
            new ScriptedRateLimiter(),
            static fn (string $staffId, string $code): bool => $code === self::CODE,
            $this->clock,
            $trail,
        );
    }

    public function testShowsTheRateInForcePerCountryWithEveryoneElseLast(): void
    {
        $view = $this->admin->overview(self::CATALOGUE, AuditFixtures::client());

        self::assertTrue($view['can_manage']);
        self::assertSame(['NG', 'US', 'ZZ'], array_column($view['countries'], 'country'));
        self::assertSame(['Nigeria', 'Everyone else'], [$view['countries'][0]['country_name'], $view['countries'][2]['country_name']]);
        self::assertSame(['tax_name' => 'VAT', 'rate_bp' => 750, 'starts' => '2026-01-01T00:00:00+00:00'], $view['countries'][0]['current']);
        self::assertSame([], $view['countries'][0]['scheduled']);
        self::assertCount(3, $view['history']);
        self::assertSame(['code' => 'ZZ', 'name' => 'Everyone else'], $view['country_options'][0]);
        self::assertContains(['code' => 'GH', 'name' => 'Ghana'], $view['country_options']);
    }

    public function testAChangeFromNowAppliesImmediatelyAndIsAudited(): void
    {
        $this->admin->change(self::CATALOGUE, AuditFixtures::client(), ['country' => 'NG', 'tax_name' => 'VAT', 'rate_bp' => 500, 'starts' => 'now', 'code' => self::CODE]);

        self::assertSame(500, $this->rates->inForce('NG', $this->clock->now())->rate->value);
        self::assertSame(750, $this->rates->inForce('NG', $this->clock->now()->modify('-1 second'))->rate->value, 'history is kept, not overwritten');
        self::assertCount(4, $this->rates->rows);
        self::assertSame([AuditEventType::TAX_RATE_CHANGED], $this->audit->types());
        self::assertSame(
            ['actor' => self::CATALOGUE, 'country' => 'ng', 'rate_bp' => 500, 'starts' => '20261025-120000', 'immediate' => true],
            $this->audit->events[0]->details,
        );
        self::assertSame(500, $this->admin->overview(self::CATALOGUE, AuditFixtures::client())['countries'][0]['current']['rate_bp'] ?? null);
    }

    public function testAFutureChangeIsScheduledAndTakesOverOnItsDate(): void
    {
        $this->admin->change(self::CATALOGUE, AuditFixtures::client(), ['country' => 'NG', 'tax_name' => 'VAT', 'rate_bp' => 1000, 'starts' => '2027-01-01', 'code' => self::CODE]);

        $view = $this->admin->overview(self::CATALOGUE, AuditFixtures::client());
        self::assertSame(750, $view['countries'][0]['current']['rate_bp'] ?? null);
        self::assertSame([['tax_name' => 'VAT', 'rate_bp' => 1000, 'starts' => '2027-01-01T00:00:00+00:00']], $view['countries'][0]['scheduled']);
        self::assertFalse($this->audit->events[0]->details['immediate']);

        $this->clock->advance('+68 days');
        self::assertSame(1000, $this->rates->inForce('NG', $this->clock->now())->rate->value);
    }

    public function testANewCountryCanBeAddedAndUnlistedCountriesUseEveryoneElse(): void
    {
        $this->admin->change(self::CATALOGUE, AuditFixtures::client(), ['country' => 'GH', 'tax_name' => 'VAT & levies', 'rate_bp' => 2190, 'starts' => 'now', 'code' => self::CODE]);

        self::assertSame(2190, $this->rates->inForce('GH', $this->clock->now())->rate->value);
        self::assertSame('ZZ', $this->rates->inForce('KE', $this->clock->now())->country);
    }

    public function testThePastIsNeverChanged(): void
    {
        foreach (['2026-10-25', '2026-01-01'] as $date) {
            try {
                $this->admin->change(self::CATALOGUE, AuditFixtures::client(), ['country' => 'NG', 'tax_name' => 'VAT', 'rate_bp' => 0, 'starts' => $date, 'code' => self::CODE]);
                self::fail('a start on or before today must be refused');
            } catch (TaxRateChangeRefused $e) {
                self::assertSame(TaxRateChangeRefused::START_IN_PAST, $e->reason);
            }
        }
        self::assertCount(3, $this->rates->rows);
    }

    public function testTheSameStartTwiceIsRefused(): void
    {
        $input = ['country' => 'NG', 'tax_name' => 'VAT', 'rate_bp' => 800, 'starts' => '2026-12-01', 'code' => self::CODE];
        $this->admin->change(self::CATALOGUE, AuditFixtures::client(), $input);

        $this->expectExceptionObject(new TaxRateChangeRefused(TaxRateChangeRefused::DUPLICATE_START));
        $this->admin->change(self::CATALOGUE, AuditFixtures::client(), $input);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function badInput(): iterable
    {
        $ok = ['country' => 'NG', 'tax_name' => 'VAT', 'rate_bp' => 750, 'starts' => 'now', 'code' => self::CODE];
        yield 'unknown country' => [['country' => 'XX'] + $ok];
        yield 'lower-case country' => [['country' => 'ng'] + $ok];
        yield 'rate above 100%' => [['rate_bp' => 10001] + $ok];
        yield 'negative rate' => [['rate_bp' => -1] + $ok];
        yield 'rate as text' => [['rate_bp' => '750'] + $ok];
        yield 'markup in the name' => [['tax_name' => '<b>VAT</b>'] + $ok];
        yield 'empty name' => [['tax_name' => ''] + $ok];
        yield 'long name' => [['tax_name' => str_repeat('V', 41)] + $ok];
        yield 'impossible date' => [['starts' => '2026-02-30'] + $ok];
        yield 'too far ahead' => [['starts' => '2028-01-01'] + $ok];
        yield 'time instead of date' => [['starts' => '2026-12-01T10:00:00Z'] + $ok];
        yield 'extra field' => [['created_by' => 'someone'] + $ok];
    }

    /** @param array<string, mixed> $input */
    #[DataProvider('badInput')]
    public function testBadInputIsRefused(array $input): void
    {
        try {
            $this->admin->change(self::CATALOGUE, AuditFixtures::client(), $input);
            self::fail('expected TaxRateChangeRefused');
        } catch (TaxRateChangeRefused $e) {
            self::assertSame(TaxRateChangeRefused::INVALID, $e->reason);
        }
        self::assertCount(3, $this->rates->rows);
    }

    public function testAChangeNeedsAFreshCodeAndIsThrottled(): void
    {
        $input = ['country' => 'NG', 'tax_name' => 'VAT', 'rate_bp' => 0, 'starts' => 'now'];
        try {
            $this->admin->change(self::CATALOGUE, AuditFixtures::client(), $input + ['code' => '000000']);
            self::fail('expected TaxRateChangeRefused');
        } catch (TaxRateChangeRefused $e) {
            self::assertSame(TaxRateChangeRefused::STEP_UP, $e->reason);
        }
        self::assertCount(3, $this->rates->rows, 'a wrong code changes nothing (C-47)');
        self::assertSame([AuditEventType::TAX_RATE_CHANGE_REFUSED], $this->audit->types());

        for ($i = 0; $i < 4; $i++) { // five attempts in all, then the sixth is refused
            try {
                $this->admin->change(self::CATALOGUE, AuditFixtures::client(), $input + ['code' => '000000']);
            } catch (TaxRateChangeRefused) {
            }
        }
        $this->expectExceptionObject(new TaxRateChangeRefused(TaxRateChangeRefused::THROTTLED, 900));
        $this->admin->change(self::CATALOGUE, AuditFixtures::client(), $input + ['code' => self::CODE]);
    }

    public function testFinanceReadsButCannotChangeAndSupportCannotRead(): void
    {
        $view = $this->admin->overview(self::FINANCE, AuditFixtures::client());
        self::assertFalse($view['can_manage']);

        try {
            $this->admin->change(self::FINANCE, AuditFixtures::client(), ['country' => 'NG', 'tax_name' => 'VAT', 'rate_bp' => 0, 'starts' => 'now', 'code' => self::CODE]);
            self::fail('Finance has no catalogue.manage');
        } catch (StaffPermissionDenied) {
        }
        self::assertCount(3, $this->rates->rows);

        $this->expectException(StaffPermissionDenied::class);
        $this->admin->overview(self::SUPPORT, AuditFixtures::client());
    }
}
