<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Domain\Money;

use PaxofiCloud\Domain\Money\BasisPoints;
use PaxofiCloud\Domain\Money\Money;
use PaxofiCloud\Domain\Money\VatPolicy;
use PHPUnit\Framework\TestCase;

/** PRC-008, BR-06/BR-07; commerce threat model C-04. */
final class VatPolicyTest extends TestCase
{
    public function testSrsAcceptanceCase(): void
    {
        $line = Money::of(1_000_000, 'NGN'); // ₦10,000.00
        $vat = VatPolicy::srsDefault()->vatOn($line, 'NG');

        self::assertSame(75_000, $vat->minorUnits); // ₦750.00
        self::assertSame(1_075_000, $line->add($vat)->minorUnits); // ₦10,750.00
    }

    public function testOnlyNigerianCustomersGetTheNigerianRate(): void
    {
        $policy = new VatPolicy(new BasisPoints(750), new BasisPoints(0));

        self::assertSame(750, $policy->rateFor('NG')->value);
        self::assertSame(0, $policy->rateFor('GH')->value);
        self::assertSame(0, $policy->vatOn(Money::of(10_000, 'USD'), 'US')->minorUnits);
    }

    public function testTheRateIsConfigurationNotCode(): void
    {
        $policy = new VatPolicy(new BasisPoints(1_000), new BasisPoints(250));

        self::assertSame(100_000, $policy->vatOn(Money::of(1_000_000, 'NGN'), 'NG')->minorUnits);
        self::assertSame(25_000, $policy->vatOn(Money::of(1_000_000, 'USD'), 'KE')->minorUnits);
    }

    public function testMalformedCountriesAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        VatPolicy::srsDefault()->rateFor('ng');
    }
}
