<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Support;

use PaxofiCloud\Domain\Catalogue\BillingCycle;
use PaxofiCloud\Domain\Catalogue\CatalogueProduct;
use PaxofiCloud\Domain\Catalogue\DatedPrice;
use PaxofiCloud\Domain\Catalogue\OptionChoice;
use PaxofiCloud\Domain\Catalogue\OptionDefinition;
use PaxofiCloud\Domain\Catalogue\OptionKind;
use PaxofiCloud\Domain\Catalogue\PlanDefinition;
use PaxofiCloud\Domain\Catalogue\PlanState;
use PaxofiCloud\Domain\Catalogue\ProductKind;
use PaxofiCloud\Domain\Money\Money;

/** A small, fully priced VPS catalogue for tests. */
final class CatalogueFixtures
{
    public static function since(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-10-01 00:00:00', new \DateTimeZone('UTC'));
    }

    public static function price(BillingCycle $cycle, int $amount, string $currency, ?int $renewal = null, ?\DateTimeImmutable $from = null): DatedPrice
    {
        return new DatedPrice($cycle, Money::of($amount, $currency), Money::of($renewal ?? $amount, $currency), $from ?? self::since());
    }

    /**
     * @param list<DatedPrice>|null $prices
     * @param list<OptionDefinition>|null $options
     */
    public static function vpsPlan(PlanState $state = PlanState::Active, ?array $prices = null, ?array $options = null, string $slug = 'vps-small'): PlanDefinition
    {
        return new PlanDefinition(
            'plan-' . $slug,
            $slug,
            ProductKind::Vps,
            'VPS Small',
            '2 vCPU, 4 GB RAM',
            $state,
            [BillingCycle::Monthly, BillingCycle::Annual],
            $prices ?? [
                self::price(BillingCycle::Monthly, 1_500_000, 'NGN'),
                self::price(BillingCycle::Monthly, 1_000, 'USD'),
                self::price(BillingCycle::Annual, 15_000_000, 'NGN', 16_500_000),
                self::price(BillingCycle::Annual, 10_000, 'USD', 11_000),
            ],
            $options ?? self::vpsOptions(),
        );
    }

    /** @return list<OptionDefinition> */
    public static function vpsOptions(): array
    {
        return [
            new OptionDefinition('location', 'Location', OptionKind::Choice, true, choices: [
                new OptionChoice('fsn1', 'Falkenstein'),
                new OptionChoice('hel1', 'Helsinki'),
            ]),
            new OptionDefinition('extra_ips', 'Extra IPv4 addresses', OptionKind::Integer, false, 0, 4, unitPrices: [
                self::price(BillingCycle::Monthly, 50_000, 'NGN'),
                self::price(BillingCycle::Monthly, 300, 'USD'),
                self::price(BillingCycle::Annual, 500_000, 'NGN'),
                self::price(BillingCycle::Annual, 3_000, 'USD'),
            ]),
            new OptionDefinition('backups', 'Daily backups', OptionKind::Boolean, false),
        ];
    }

    /** @param list<PlanDefinition> $plans */
    public static function vpsProduct(array $plans): CatalogueProduct
    {
        return new CatalogueProduct('product-vps', 'cloud-vps', ProductKind::Vps, 'Cloud VPS', 'Virtual servers', $plans);
    }
}
