<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Application\Extensions;

use PaxofiCloud\Application\Extensions\RegistrarChoice;
use PaxofiCloud\Application\Extensions\RegistrarOffer;
use PaxofiCloud\Domain\Pricing\CostCurrency;
use PaxofiCloud\Domain\Registrar\CostAmount;
use PaxofiCloud\Domain\Registrar\TldCost;
use PaxofiCloud\Domain\Registrar\TldName;
use PHPUnit\Framework\TestCase;

/** THREAT-MODEL-DOMAIN-PRICING.md DX-2, DX-3 / DX-T12. */
final class RegistrarChoiceTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-10-25T12:00:00Z');
    }

    private function offer(string $registrar, int $register, string $age = '-1 hour', CostCurrency $currency = CostCurrency::USD): RegistrarOffer
    {
        return new RegistrarOffer(
            $registrar,
            new TldCost(new TldName('com'), CostAmount::ofMinor($currency, $register), CostAmount::ofMinor($currency, $register + 100), null, null, 1, 10, 'active'),
            $this->now->modify($age),
        );
    }

    public function testTheCheapestHealthyOfferWinsAndTiesGoToTheRanking(): void
    {
        $choice = RegistrarChoice::choose([$this->offer('dynadot', 1000), $this->offer('namecheap', 900), $this->offer('domainnameapi', 900)], null, $this->now);

        self::assertSame('domainnameapi', $choice->chosen?->registrar, 'tie at 900: DomainNameAPI ranks first');
        self::assertSame(['domainnameapi', 'namecheap', 'dynadot'], array_map(static fn (RegistrarOffer $o): string => $o->registrar, $choice->healthy));
        self::assertFalse($choice->pinUnavailable);
    }

    public function testStaleNonUsdAndUnknownOffersAreNotHealthy(): void
    {
        $choice = RegistrarChoice::choose([
            $this->offer('namecheap', 500, '-73 hours'),
            $this->offer('dynadot', 400, '-1 hour', CostCurrency::EUR),
            $this->offer('somebody', 300),
            $this->offer('domainnameapi', 1200, '-71 hours'),
        ], null, $this->now);

        self::assertSame('domainnameapi', $choice->chosen?->registrar);
        self::assertCount(1, $choice->healthy);
        self::assertNull(RegistrarChoice::choose([$this->offer('namecheap', 500, '-80 hours')], null, $this->now)->chosen);
    }

    public function testAHealthyPinWinsAndAnUnhealthyPinFallsBackToTheCheapest(): void
    {
        $offers = [$this->offer('namecheap', 900), $this->offer('dynadot', 1100), $this->offer('domainnameapi', 1000, '-100 hours')];

        $pinned = RegistrarChoice::choose($offers, 'dynadot', $this->now);
        self::assertSame('dynadot', $pinned->chosen?->registrar);
        self::assertFalse($pinned->pinUnavailable);

        $fallback = RegistrarChoice::choose($offers, 'domainnameapi', $this->now);
        self::assertSame('namecheap', $fallback->chosen?->registrar);
        self::assertTrue($fallback->pinUnavailable);
    }
}
