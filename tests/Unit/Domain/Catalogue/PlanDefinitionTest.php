<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Domain\Catalogue;

use PaxofiCloud\Domain\Catalogue\BillingCycle;
use PaxofiCloud\Domain\Catalogue\CatalogueRuleViolation;
use PaxofiCloud\Domain\Catalogue\OptionDefinition;
use PaxofiCloud\Domain\Catalogue\OptionKind;
use PaxofiCloud\Domain\Catalogue\PlanDefinition;
use PaxofiCloud\Domain\Catalogue\PlanState;
use PaxofiCloud\Domain\Catalogue\ProductKind;
use PaxofiCloud\Domain\Money\Currency;
use PaxofiCloud\Tests\Support\CatalogueFixtures as F;
use PHPUnit\Framework\TestCase;

/** CAT-002, CAT-004, PRC-002, PRC-005, PRC-006; commerce threat model C-05, C-07, C-08, C-10. */
final class PlanDefinitionTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-10-20 12:00:00', new \DateTimeZone('UTC'));
    }

    /** PlanStateGateTest: only ACTIVE accepts new orders; the others are refused (C-07). */
    public function testOnlyActivePlansAreForSale(): void
    {
        foreach (PlanState::cases() as $state) {
            self::assertSame($state === PlanState::Active, F::vpsPlan($state)->isForSale($this->now), $state->value);
        }
        self::assertTrue(PlanState::Deprecated->renews(), 'deprecated plans keep renewing (CAT-002)');
        self::assertFalse(PlanState::Retired->renews());
    }

    public function testStateTransitionsFollowTheLifecycle(): void
    {
        self::assertTrue(PlanState::Draft->canMoveTo(PlanState::Active));
        self::assertTrue(PlanState::Active->canMoveTo(PlanState::Limited));
        self::assertTrue(PlanState::Deprecated->canMoveTo(PlanState::Retired));
        self::assertFalse(PlanState::Retired->canMoveTo(PlanState::Active), 'a retired plan never comes back');
        self::assertFalse(PlanState::Deprecated->canMoveTo(PlanState::Active));
        self::assertFalse(PlanState::Draft->canMoveTo(PlanState::Retired));

        $this->expectException(CatalogueRuleViolation::class);
        F::vpsPlan(PlanState::Retired)->assertCanMoveTo(PlanState::Active, $this->now);
    }

    /** PlanActivationNeedsBothCurrenciesTest (PRC-002, C-10). */
    public function testAPlanMissingAUsdPriceCannotBeActivatedOrSold(): void
    {
        $plan = F::vpsPlan(PlanState::Draft, [
            F::price(BillingCycle::Monthly, 1_500_000, 'NGN'),
            F::price(BillingCycle::Annual, 15_000_000, 'NGN'),
            F::price(BillingCycle::Annual, 10_000, 'USD'),
        ]);
        self::assertFalse($plan->isFullyPriced($this->now));
        try {
            $plan->assertCanMoveTo(PlanState::Active, $this->now);
            self::fail('Activated without a USD monthly price');
        } catch (CatalogueRuleViolation $e) {
            self::assertSame('prices_incomplete', $e->reason);
        }
        F::vpsPlan(PlanState::Draft)->assertCanMoveTo(PlanState::Active, $this->now);
        self::assertFalse(F::vpsPlan(PlanState::Active, prices: [F::price(BillingCycle::Monthly, 1, 'NGN')])->isForSale($this->now), 'an active plan with missing prices is not shown for sale');
    }

    public function testPricedOptionsMustBePricedInBothCurrencies(): void
    {
        $options = [new OptionDefinition('extra_ips', 'Extra IPs', OptionKind::Integer, false, 0, 4, unitPrices: [F::price(BillingCycle::Monthly, 50_000, 'NGN')])];

        self::assertFalse(F::vpsPlan(options: $options)->isFullyPriced($this->now));
    }

    /** PriceEffectiveDateTest (PRC-005, C-05): the latest row not after "now" applies; future rows wait. */
    public function testThePriceInForceIsTheLatestNotInTheFuture(): void
    {
        $plan = F::vpsPlan(prices: [
            F::price(BillingCycle::Monthly, 1_000, 'USD'),
            F::price(BillingCycle::Monthly, 1_200, 'USD', from: new \DateTimeImmutable('2026-10-15', new \DateTimeZone('UTC'))),
            F::price(BillingCycle::Monthly, 9_999, 'USD', from: new \DateTimeImmutable('2026-11-01', new \DateTimeZone('UTC'))),
        ]);

        self::assertSame(1_200, $plan->price(BillingCycle::Monthly, Currency::USD, $this->now)?->firstTerm->minorUnits);
        self::assertSame(1_000, $plan->price(BillingCycle::Monthly, Currency::USD, new \DateTimeImmutable('2026-10-10', new \DateTimeZone('UTC')))?->firstTerm->minorUnits);
        self::assertSame(9_999, $plan->price(BillingCycle::Monthly, Currency::USD, new \DateTimeImmutable('2026-11-02', new \DateTimeZone('UTC')))?->firstTerm->minorUnits);
        self::assertNull($plan->price(BillingCycle::Biennial, Currency::USD, $this->now), 'a cycle the plan does not offer has no price');
    }

    public function testRenewalPriceIsKeptSeparately(): void
    {
        $price = F::vpsPlan()->price(BillingCycle::Annual, Currency::NGN, $this->now);

        self::assertNotNull($price);
        self::assertSame(15_000_000, $price->firstTerm->minorUnits);
        self::assertSame(16_500_000, $price->renewal->minorUnits);
    }

    /** OptionValidationTest (CAT-004, C-08): the SRS case and the other shapes; errors name the option. */
    public function testOptionsAreValidatedAgainstThePlan(): void
    {
        $plan = F::vpsPlan();

        self::assertSame(['location' => 'fsn1', 'extra_ips' => 2, 'backups' => true], $plan->validateOptions(['location' => 'fsn1', 'extra_ips' => 2, 'backups' => true]));
        self::assertSame(['location' => 'hel1'], $plan->validateOptions(['location' => 'hel1']));
        foreach ([
            [['location' => 'xyz'], 'location', 'invalid_value'],
            [[], 'location', 'required'],
            [['location' => 'fsn1', 'extra_ips' => 5], 'extra_ips', 'out_of_range'],
            [['location' => 'fsn1', 'extra_ips' => '2'], 'extra_ips', 'out_of_range'],
            [['location' => 'fsn1', 'backups' => 'yes'], 'backups', 'invalid_value'],
            [['location' => 'fsn1', 'os' => 'debian'], 'os', 'unknown_option'],
            [['location' => 'fsn1', 'DROP TABLE' => 1], 'option', 'unknown_option'],
            [['location' => ['fsn1']], 'location', 'invalid_value'],
        ] as [$request, $field, $reason]) {
            try {
                $plan->validateOptions($request);
                self::fail('Accepted ' . json_encode($request));
            } catch (CatalogueRuleViolation $e) {
                self::assertSame([$field, $reason], [$e->field, $e->reason]);
            }
        }
    }

    public function testCyclesMustSuitTheProductKind(): void
    {
        $this->expectException(CatalogueRuleViolation::class);
        new PlanDefinition('p', 'domain-ng', ProductKind::Domain, '.ng', '', PlanState::Draft, [BillingCycle::Monthly], [], []);
    }
}
