<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Support;

use PaxofiCloud\Application\Contracts\TaxRateStore;
use PaxofiCloud\Domain\Billing\TaxRate;
use PaxofiCloud\Domain\Money\BasisPoints;

/** The CEO-approved launch rows (CD-22): NG VAT 7.5%, US 0%, everyone else 0%. */
final class InMemoryTaxRateStore implements TaxRateStore
{
    /** @var array<string, TaxRate> */
    public array $rates;

    public function __construct()
    {
        $this->rates = [
            'NG' => new TaxRate('NG', 'VAT', new BasisPoints(750)),
            'US' => new TaxRate('US', 'Sales tax', new BasisPoints(0)),
            'ZZ' => new TaxRate('ZZ', 'Tax', new BasisPoints(0)),
        ];
    }

    public function inForce(string $country, \DateTimeImmutable $at): TaxRate
    {
        return $this->rates[$country] ?? $this->rates['ZZ'];
    }
}
