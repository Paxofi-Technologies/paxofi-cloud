<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Application\Checkout;

use PaxofiCloud\Application\Audit\AuditEventType;
use PaxofiCloud\Application\Billing\BillingDetails;
use PaxofiCloud\Application\Cart\DomainCart;
use PaxofiCloud\Application\Checkout\Checkout;
use PaxofiCloud\Application\Checkout\CheckoutRefused;
use PaxofiCloud\Application\Domains\RegistrarCheck;
use PaxofiCloud\Application\Extensions\ExtensionQuotes;
use PaxofiCloud\Domain\Billing\TaxRate;
use PaxofiCloud\Domain\Money\BasisPoints;
use PaxofiCloud\Domain\Money\Currency;
use PaxofiCloud\Domain\Pricing\DecimalRate;
use PaxofiCloud\Domain\Pricing\DomainPricingSettings;
use PaxofiCloud\Domain\Pricing\ExchangeRates;
use PaxofiCloud\Domain\Pricing\FxRateVerdict;
use PaxofiCloud\Domain\Pricing\Markup;
use PaxofiCloud\Domain\Pricing\RoundingStep;
use PaxofiCloud\Tests\Support\AuditFixtures;
use PaxofiCloud\Tests\Support\InMemoryAuditLog;
use PaxofiCloud\Tests\Support\InMemoryBillingStore;
use PaxofiCloud\Tests\Support\InMemoryDomainCostStore;
use PaxofiCloud\Tests\Support\InMemoryExtensionSettingsStore;
use PaxofiCloud\Tests\Support\InMemoryFxRateStore;
use PaxofiCloud\Tests\Support\InMemoryOrderStore;
use PaxofiCloud\Tests\Support\InMemoryRedis;
use PaxofiCloud\Tests\Support\InMemoryTaxRateStore;
use PaxofiCloud\Tests\Support\MutableClock;
use PaxofiCloud\Tests\Support\ScriptedPriceFeed;
use PaxofiCloud\Tests\Support\ScriptedRateLimiter;
use PHPUnit\Framework\TestCase;

/**
 * THREAT-MODEL-COMMERCE.md CD-23…CD-25, C-17, C-18, C-23, C-24, C-41…C-45;
 * SRS CRT-003, CRT-005, CMP-010, PRC-008. Test accounts and names only.
 */
final class CheckoutTest extends TestCase
{
    private const string ALICE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const string ALICE_ID = '11111111111111111111111111111111';
    private const string BOB = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const string BOB_ID = '22222222222222222222222222222222';
    private const string KEY = 'order-key-0000000001';

    private InMemoryRedis $redis;
    private InMemoryExtensionSettingsStore $settings;
    private InMemoryBillingStore $billing;
    private InMemoryOrderStore $orders;
    private InMemoryAuditLog $audit;
    private ScriptedRateLimiter $limiter;
    private MutableClock $clock;
    private InMemoryTaxRateStore $taxes;
    /** @var array<string, int> */
    private array $costs = ['com' => 1000, 'ng' => 900];

    protected function setUp(): void
    {
        $this->redis = new InMemoryRedis();
        $this->limiter = new ScriptedRateLimiter();
        $this->settings = new InMemoryExtensionSettingsStore();
        $this->settings->pricing = new DomainPricingSettings(new Markup(1000), new BasisPoints(1000), new RoundingStep(1), new RoundingStep(5000));
        $this->billing = new InMemoryBillingStore();
        $this->billing->owners = [self::ALICE => self::ALICE_ID, self::BOB => self::BOB_ID];
        $this->orders = new InMemoryOrderStore();
        $this->orders->billing = $this->billing;
        $this->audit = new InMemoryAuditLog();
        $this->taxes = new InMemoryTaxRateStore();
        $this->clock = new MutableClock(new \DateTimeImmutable('2026-10-25T12:00:00Z'));
        $this->billTo(self::ALICE, 'NG');
    }

    private function billTo(string $account, string $country): void
    {
        $details = BillingDetails::fromInput([
            'full_name' => 'Test Person', 'address_line1' => '1 Test Street', 'city' => 'Ikeja', 'region' => 'Lagos',
            'country' => $country, 'phone' => '+2348000000000',
        ]);
        $this->billing->save($account, $details, $details->defaultCurrency(), $this->clock->now());
    }

    /** A new checkout per call, like a new request: prices are quoted from the current costs. */
    private function checkout(): Checkout
    {
        $costs = new InMemoryDomainCostStore();
        $costs->saveSnapshot('domainnameapi', ScriptedPriceFeed::list($this->costs), null, new \DateTimeImmutable('2026-10-25T02:30:00Z'));
        $rates = new InMemoryFxRateStore();
        $rates->record(new ExchangeRates(['USD' => new DecimalRate('1'), 'NGN' => new DecimalRate('1500'), 'EUR' => new DecimalRate('0.9')], 'test', new \DateTimeImmutable()), FxRateVerdict::Accepted, new \DateTimeImmutable('2026-10-25T01:00:00Z'));
        $quotes = new ExtensionQuotes(['domainnameapi' => 'live'], $costs, $this->settings, $rates, $this->clock);

        return new Checkout(
            new DomainCart($this->redis, $quotes, $this->limiter),
            $quotes,
            new RegistrarCheck($quotes, $this->redis, $this->limiter),
            $this->billing,
            $this->taxes,
            $this->orders,
            $this->redis,
            $this->limiter,
            AuditFixtures::trail($this->audit),
            $this->clock,
            '2026-10-01',
        );
    }

    private function cart(string $account, string ...$domains): void
    {
        foreach ($domains as $domain) {
            (new DomainCart($this->redis, $this->quotes(), $this->limiter))->addDomain($account, $domain);
        }
    }

    private function quotes(): ExtensionQuotes
    {
        $costs = new InMemoryDomainCostStore();
        $costs->saveSnapshot('domainnameapi', ScriptedPriceFeed::list($this->costs), null, new \DateTimeImmutable('2026-10-25T02:30:00Z'));
        $rates = new InMemoryFxRateStore();
        $rates->record(new ExchangeRates(['USD' => new DecimalRate('1'), 'NGN' => new DecimalRate('1500'), 'EUR' => new DecimalRate('0.9')], 'test', new \DateTimeImmutable()), FxRateVerdict::Accepted, new \DateTimeImmutable('2026-10-25T01:00:00Z'));

        return new ExtensionQuotes(['domainnameapi' => 'live'], $costs, $this->settings, $rates, $this->clock);
    }

    /**
     * Plays the provider worker: answers every queued job (DS-13).
     *
     * @param array<string, array{0: string, 1?: bool}> $answers
     */
    private function registrarAnswers(array $answers): void
    {
        while (($id = $this->redis->popRight(RegistrarCheck::QUEUE)) !== null) {
            $names = json_decode((string) $this->redis->get(RegistrarCheck::jobKey($id)), true);
            $results = [];
            foreach (is_array($names) ? array_filter($names, 'is_string') : [] as $name) {
                $answer = $answers[$name] ?? ['unknown'];
                $results[] = ['domain' => $name, 'status' => $answer[0], 'premium' => $answer[1] ?? false, 'register' => null];
            }
            $this->redis->setEx(RegistrarCheck::resultKey($id), RegistrarCheck::TTL_SECONDS, json_encode($results, JSON_THROW_ON_ERROR));
        }
    }

    private function readyReview(string $account = self::ALICE): string
    {
        $id = $this->checkout()->startReview($account, 'NG');
        $this->registrarAnswers(['paxofi-test.com' => ['available'], 'paxofi-test.ng' => ['available']]);

        return $id;
    }

    public function testReviewIsPendingUntilTheRegistrarAnswersThenShowsLockedPricesTaxAndRenewal(): void
    {
        $this->cart(self::ALICE, 'paxofi-test.com');
        $id = $this->checkout()->startReview(self::ALICE, 'NG');
        self::assertSame('pending', $this->checkout()->review(self::ALICE, $id)['status']);

        $this->registrarAnswers(['paxofi-test.com' => ['available']]);
        $review = $this->checkout()->review(self::ALICE, $id);

        self::assertSame('ready', $review['status']);
        self::assertFalse($review['blocked']);
        self::assertSame('NGN', $review['currency']);
        // $11.00 at ₦1,500 = ₦16,500.00; VAT 7.5% = ₦1,237.50; renewal $12.10 = ₦18,150.00 (CMP-010).
        self::assertSame(
            [['domain' => 'paxofi-test.com', 'years' => 1, 'status' => 'available', 'price' => 1_650_000, 'tax' => 123_750, 'total' => 1_773_750, 'renewal_price' => 1_815_000]],
            $review['lines'],
        );
        self::assertSame(['name' => 'VAT', 'rate_bp' => 750, 'amount' => 123_750], $review['tax']);
        self::assertSame(1_773_750, $review['total']);
        self::assertFalse($review['auto_renew']);
        $json = json_encode($review, JSON_THROW_ON_ERROR);
        foreach (['registrar', 'cost', 'fx', 'domainnameapi'] as $secret) {
            self::assertStringNotContainsString($secret, $json, 'costs and registrars never reach the customer (CD-5)');
        }
    }

    public function testTakenUnknownAndPremiumNamesBlockTheOrder(): void
    {
        $this->cart(self::ALICE, 'taken-test.com', 'unsure-test.com', 'premium-test.com', 'free-test.com');
        $id = $this->checkout()->startReview(self::ALICE, 'NG');
        $this->registrarAnswers(['taken-test.com' => ['taken'], 'premium-test.com' => ['available', true], 'free-test.com' => ['available']]);

        $review = $this->checkout()->review(self::ALICE, $id);
        self::assertTrue($review['blocked']);
        self::assertSame(['taken', 'unknown', 'premium', 'available'], array_column($review['lines'], 'status'));
        self::assertSame(1_650_000, $review['subtotal'], 'only available lines count');

        $this->expectExceptionObject(new CheckoutRefused(CheckoutRefused::REVIEW_BLOCKED));
        $this->checkout()->place(self::ALICE_ID, self::ALICE, $id, true, false, self::KEY, AuditFixtures::client());
    }

    public function testPlacesOneOrderEmptiesTheCartLocksTheCurrencyAndAudits(): void
    {
        $this->cart(self::ALICE, 'paxofi-test.com', 'paxofi-test.ng');
        $id = $this->readyReview();

        $placed = $this->checkout()->place(self::ALICE_ID, self::ALICE, $id, true, false, self::KEY, AuditFixtures::client());

        self::assertFalse($placed['replayed']);
        self::assertSame('PXO-2026-000001', $placed['order_number']);
        self::assertSame('PXC-2026-000001', $placed['invoice_number']);
        $order = $this->orders->orders[$placed['order_id']];
        self::assertSame(Currency::NGN, $order->currency);
        self::assertSame(['paxofi-test.com', 'paxofi-test.ng'], array_map(static fn ($l): string => $l->domain, $order->lines));
        self::assertSame('domainnameapi', $order->lines[0]->registrar, 'the cost basis is locked on the line (DX-6)');
        self::assertSame(1000, $order->lines[0]->costMinor);
        self::assertSame('2026-10-01', $order->termsVersion);
        self::assertSame('2026-10-28T12:00:00+00:00', $order->expiresAt->format(\DATE_ATOM), 'BR-10: 72 hours');
        self::assertTrue($this->billing->find(self::ALICE)?->currencyLocked());
        self::assertSame([], (new DomainCart($this->redis, $this->quotes(), $this->limiter))->domains(self::ALICE));
        self::assertSame([AuditEventType::ORDER_PLACED], $this->audit->types());

        $this->expectExceptionObject(new CheckoutRefused(CheckoutRefused::REVIEW_NOT_FOUND));
        $this->checkout()->review(self::ALICE, $id);
    }

    public function testTheSameIdempotencyKeyReturnsTheFirstOrderAndAnotherReviewWithItIsRefused(): void
    {
        $this->cart(self::ALICE, 'paxofi-test.com');
        $id = $this->readyReview();
        $first = $this->checkout()->place(self::ALICE_ID, self::ALICE, $id, true, false, self::KEY, AuditFixtures::client());
        $again = $this->checkout()->place(self::ALICE_ID, self::ALICE, $id, true, false, self::KEY, AuditFixtures::client());

        self::assertTrue($again['replayed']);
        self::assertSame($first['order_id'], $again['order_id']);
        self::assertCount(1, $this->orders->orders, 'CRT-005: exactly one order');

        $this->cart(self::ALICE, 'paxofi-test.ng');
        $other = $this->readyReview();
        $this->expectExceptionObject(new CheckoutRefused(CheckoutRefused::KEY_REUSED));
        $this->checkout()->place(self::ALICE_ID, self::ALICE, $other, true, false, self::KEY, AuditFixtures::client());
    }

    public function testAPriceChangeAfterTheReviewStopsTheOrder(): void
    {
        $this->cart(self::ALICE, 'paxofi-test.com');
        $id = $this->readyReview();
        $this->costs['com'] = 1300;

        try {
            $this->checkout()->place(self::ALICE_ID, self::ALICE, $id, true, false, self::KEY, AuditFixtures::client());
            self::fail('a changed price must stop the order');
        } catch (CheckoutRefused $e) {
            self::assertSame(CheckoutRefused::PRICE_CHANGED, $e->reason);
        }
        self::assertSame([], $this->orders->orders);
    }

    public function testAVatChangeAfterTheReviewStopsTheOrder(): void
    {
        $this->cart(self::ALICE, 'paxofi-test.com');
        $id = $this->readyReview();
        // Staff raise Nigerian VAT from now on (CD-28): the open review no longer matches.
        $this->taxes->add(new TaxRate('NG', 'VAT', new BasisPoints(1000)), $this->clock->now(), 'staff');

        try {
            $this->checkout()->place(self::ALICE_ID, self::ALICE, $id, true, false, self::KEY, AuditFixtures::client());
            self::fail('a changed tax must stop the order');
        } catch (CheckoutRefused $e) {
            self::assertSame(CheckoutRefused::PRICE_CHANGED, $e->reason);
        }
        self::assertSame([], $this->orders->orders);
    }

    public function testAnotherAccountAndAnExpiredReviewSeeNothing(): void
    {
        $this->cart(self::ALICE, 'paxofi-test.com');
        $id = $this->readyReview();
        $this->billTo(self::BOB, 'NG');

        foreach ([fn () => $this->checkout()->review(self::BOB, $id), fn () => $this->checkout()->place(self::BOB_ID, self::BOB, $id, true, false, self::KEY, AuditFixtures::client())] as $call) {
            try {
                $call();
                self::fail('another account must not see the review');
            } catch (CheckoutRefused $e) {
                self::assertSame(CheckoutRefused::REVIEW_NOT_FOUND, $e->reason);
            }
        }

        $this->clock->advance('+31 minutes');
        $this->expectExceptionObject(new CheckoutRefused(CheckoutRefused::REVIEW_NOT_FOUND));
        $this->checkout()->review(self::ALICE, $id);
    }

    public function testPreconditionsTermsOwnerAndKeyFormat(): void
    {
        try {
            $this->checkout()->startReview(self::ALICE, 'NG');
            self::fail('empty cart');
        } catch (CheckoutRefused $e) {
            self::assertSame(CheckoutRefused::CART_EMPTY, $e->reason);
        }
        $this->cart(self::BOB, 'paxofi-test.com');
        try {
            $this->checkout()->startReview(self::BOB, 'NG');
            self::fail('no billing details');
        } catch (CheckoutRefused $e) {
            self::assertSame(CheckoutRefused::BILLING_REQUIRED, $e->reason);
        }

        $this->cart(self::ALICE, 'paxofi-test.com');
        $id = $this->readyReview();
        foreach ([
            [self::ALICE_ID, false, self::KEY, CheckoutRefused::TERMS_REQUIRED],
            [self::BOB_ID, true, self::KEY, CheckoutRefused::NOT_OWNER],
            [self::ALICE_ID, true, 'short', CheckoutRefused::KEY_INVALID],
            [self::ALICE_ID, true, str_repeat('k', 16) . '<x>', CheckoutRefused::KEY_INVALID],
        ] as [$identity, $terms, $key, $reason]) {
            try {
                $this->checkout()->place($identity, self::ALICE, $id, $terms, false, $key, AuditFixtures::client());
                self::fail('must be refused: ' . $reason);
            } catch (CheckoutRefused $e) {
                self::assertSame($reason, $e->reason);
            }
        }
        self::assertSame([], $this->orders->orders);
    }

    public function testALowerTaxBillingCountryThanTheVisitorsNeedsConfirmationAndFlagsTheOrder(): void
    {
        // C-24 / PRC-008: billed in Ghana (0%) from a Nigerian IP (7.5%).
        $this->billTo(self::ALICE, 'GH');
        $this->cart(self::ALICE, 'paxofi-test.com');
        $id = $this->readyReview();
        $review = $this->checkout()->review(self::ALICE, $id);
        self::assertTrue($review['confirm_billing_country']);
        self::assertSame('USD', $review['currency']);
        self::assertSame(0, $review['tax']['amount']);

        try {
            $this->checkout()->place(self::ALICE_ID, self::ALICE, $id, true, false, self::KEY, AuditFixtures::client());
            self::fail('confirmation required');
        } catch (CheckoutRefused $e) {
            self::assertSame(CheckoutRefused::COUNTRY_CONFIRMATION, $e->reason);
        }
        $placed = $this->checkout()->place(self::ALICE_ID, self::ALICE, $id, true, true, self::KEY, AuditFixtures::client());
        $order = $this->orders->orders[$placed['order_id']];
        self::assertTrue($order->flaggedForReview);
        self::assertSame('NG', $order->visitorCountry);
        self::assertSame(1100, $order->lines[0]->priceMinor);
    }

    public function testReviewsAreRateLimitedPerAccount(): void
    {
        $this->cart(self::ALICE, 'paxofi-test.com');
        for ($i = 0; $i < Checkout::REVIEWS_PER_HOUR; ++$i) {
            $this->checkout()->startReview(self::ALICE, null);
        }
        $this->expectExceptionObject(new CheckoutRefused(CheckoutRefused::TOO_MANY));
        $this->checkout()->startReview(self::ALICE, null);
    }
}
