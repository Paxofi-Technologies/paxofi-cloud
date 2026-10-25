<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Checkout;

/** One locked order line (CD-24, ORD-002): price, tax, renewal price and the cost basis at placement. */
final readonly class NewOrderLine
{
    public function __construct(
        public string $domain,
        public int $priceMinor,
        public int $taxMinor,
        public int $renewalPriceMinor,
        public string $registrar,
        public int $costMinor,
        public string $costCurrency,
        public string $fxSetId,
    ) {
    }

    public function totalMinor(): int
    {
        return $this->priceMinor + $this->taxMinor;
    }
}
