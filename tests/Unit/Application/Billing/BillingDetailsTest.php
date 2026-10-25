<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Application\Billing;

use PaxofiCloud\Application\Audit\AuditEventType;
use PaxofiCloud\Application\Billing\BillingDetails;
use PaxofiCloud\Application\Billing\BillingDetailsService;
use PaxofiCloud\Application\Billing\BillingRefused;
use PaxofiCloud\Application\Billing\InvalidBillingDetails;
use PaxofiCloud\Domain\Money\Currency;
use PaxofiCloud\Domain\Money\Money;
use PaxofiCloud\Tests\Support\AuditFixtures;
use PaxofiCloud\Tests\Support\FixedClock;
use PaxofiCloud\Tests\Support\InMemoryAuditLog;
use PaxofiCloud\Tests\Support\InMemoryBillingStore;
use PaxofiCloud\Tests\Support\InMemoryTaxRateStore;
use PaxofiCloud\Tests\Support\ScriptedRateLimiter;
use PHPUnit\Framework\TestCase;

/** THREAT-MODEL-COMMERCE.md CD-21, CD-22, C-38…C-40; SRS ACC-004, PRC-003, PRC-008. Test data only. */
final class BillingDetailsTest extends TestCase
{
    private const string OWNER = '11111111111111111111111111111111';
    private const string OTHER = '22222222222222222222222222222222';
    private const string ACCOUNT = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private InMemoryBillingStore $store;
    private InMemoryAuditLog $audit;
    private ScriptedRateLimiter $limiter;
    private BillingDetailsService $service;

    protected function setUp(): void
    {
        $this->store = new InMemoryBillingStore();
        $this->store->owners[self::ACCOUNT] = self::OWNER;
        $this->audit = new InMemoryAuditLog();
        $this->limiter = new ScriptedRateLimiter();
        $this->service = new BillingDetailsService(
            $this->store,
            new InMemoryTaxRateStore(),
            $this->limiter,
            AuditFixtures::trail($this->audit),
            new FixedClock(new \DateTimeImmutable('2026-10-26 09:00:00 UTC')),
        );
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function input(string $country = 'NG', array $overrides = []): array
    {
        return array_merge([
            'full_name' => 'Ada Test',
            'company' => '',
            'address_line1' => '1 Test Street',
            'address_line2' => '',
            'city' => 'Ikeja',
            'region' => 'Lagos',
            'postcode' => '100001',
            'country' => $country,
            'phone' => '+2348000000000',
            'tin' => '',
        ], $overrides);
    }

    public function testOwnerSavesDetailsAndNigeriaBillsInNaira(): void
    {
        $saved = $this->service->save(self::OWNER, self::ACCOUNT, self::input(), AuditFixtures::client());

        self::assertSame(Currency::NGN, $saved->currency);
        self::assertFalse($saved->currencyLocked());
        self::assertNull($saved->details->company, 'blank optional fields are stored as null');
        self::assertSame([AuditEventType::BILLING_DETAILS_SAVED], $this->audit->types());
        $details = $this->audit->events[0]->details;
        self::assertSame('ngn', $details['currency']);
        self::assertSame(10, $details['changed']);
        self::assertStringNotContainsString('Ada', implode('|', array_map('strval', $details)), 'the audit log names fields, never values');
    }

    public function testCurrencyFollowsCountryUntilTheFirstInvoiceLocksIt(): void
    {
        $this->service->save(self::OWNER, self::ACCOUNT, self::input('NG'), AuditFixtures::client());
        self::assertSame(Currency::USD, $this->service->save(self::OWNER, self::ACCOUNT, self::input('GH'), AuditFixtures::client())->currency);
        self::assertSame(Currency::NGN, $this->service->save(self::OWNER, self::ACCOUNT, self::input('NG'), AuditFixtures::client())->currency);

        $this->store->lock(self::ACCOUNT, new \DateTimeImmutable('2026-10-26 10:00:00 UTC'));
        $after = $this->service->save(self::OWNER, self::ACCOUNT, self::input('GH'), AuditFixtures::client());

        // PRC-003 acceptance: country changes to Ghana, the currency stays NGN.
        self::assertSame('GH', $after->details->country);
        self::assertSame(Currency::NGN, $after->currency);
        self::assertTrue($after->currencyLocked());
    }

    public function testOnlyTheAccountOwnerCanSave(): void
    {
        try {
            $this->service->save(self::OTHER, self::ACCOUNT, self::input(), AuditFixtures::client());
            self::fail('a non-owner must be refused');
        } catch (BillingRefused $e) {
            self::assertSame(BillingRefused::NOT_OWNER, $e->reason);
        }
        self::assertNull($this->store->find(self::ACCOUNT));
    }

    public function testSavesAreRateLimitedPerAccount(): void
    {
        for ($i = 0; $i < BillingDetailsService::SAVES_PER_HOUR; ++$i) {
            $this->service->save(self::OWNER, self::ACCOUNT, self::input(), AuditFixtures::client());
        }
        $this->expectException(BillingRefused::class);
        $this->service->save(self::OWNER, self::ACCOUNT, self::input(), AuditFixtures::client());
    }

    public function testAuditNamesOnlyTheChangedFields(): void
    {
        $this->service->save(self::OWNER, self::ACCOUNT, self::input(), AuditFixtures::client());
        $this->service->save(self::OWNER, self::ACCOUNT, self::input('NG', ['city' => 'Lekki', 'tin' => '12345678-0001']), AuditFixtures::client());

        self::assertSame(
            ['changed' => 2, 'name_changed' => false, 'address_changed' => true, 'country_changed' => false, 'tin_changed' => true, 'currency' => 'ngn'],
            $this->audit->events[1]->details,
        );
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidInputs(): iterable
    {
        yield 'script in name' => [['full_name' => '<script>x</script>'], 'full_name'];
        yield 'line break in address' => [['address_line1' => "1 Road\r\nBcc: x"], 'address_line1'];
        yield 'control character' => [['city' => "Ike\x07ja"], 'city'];
        yield 'missing city' => [['city' => '   '], 'city'];
        yield 'too long company' => [['company' => str_repeat('a', 101)], 'company'];
        yield 'unknown country' => [['country' => 'XX'], 'country'];
        yield 'lower-case country' => [['country' => 'ng'], 'country'];
        yield 'local phone' => [['phone' => '08000000000'], 'phone'];
        yield 'bad postcode' => [['postcode' => '100;001'], 'postcode'];
        yield 'bad tin' => [['tin' => 'TIN 123'], 'tin'];
        yield 'number instead of text' => [['region' => 5], 'region'];
    }

    /**
     * @dataProvider invalidInputs
     * @param array<string, mixed> $override
     */
    public function testRefusesUnsafeOrIncompleteFields(array $override, string $field): void
    {
        try {
            BillingDetails::fromInput(self::input('NG', $override));
            self::fail('invalid input must be refused');
        } catch (InvalidBillingDetails $e) {
            self::assertArrayHasKey($field, $e->errors);
        }
    }

    public function testRefusesUnexpectedFields(): void
    {
        $this->expectException(InvalidBillingDetails::class);
        BillingDetails::fromInput(self::input() + ['billing_currency' => 'USD']);
    }

    public function testTaxComesFromTheTableWithAFallback(): void
    {
        $ng = $this->service->save(self::OWNER, self::ACCOUNT, self::input('NG'), AuditFixtures::client());
        $tax = $this->service->taxFor($ng);
        self::assertSame(['VAT', 750], [$tax->name, $tax->rate->value]);
        // PRC-008 acceptance: ₦10,000.00 at 7.5% is ₦750.00.
        self::assertSame(75_000, $tax->on(Money::of(1_000_000, 'NGN'))->minorUnits);

        $gh = $this->service->save(self::OWNER, self::ACCOUNT, self::input('GH'), AuditFixtures::client());
        self::assertSame(['ZZ', 0], [$this->service->taxFor($gh)->country, $this->service->taxFor($gh)->rate->value]);
    }
}
