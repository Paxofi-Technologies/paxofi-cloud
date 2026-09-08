<?php

declare(strict_types=1);

namespace PaxofiCloud\Domain\Order;

use PaxofiCloud\Domain\Catalogue\Money;
use PaxofiCloud\Domain\Tenant\TenantId;

final readonly class Order
{
    public Money $total;

    /** @param list<CommercialSnapshotLine> $lines */
    public function __construct(
        public string $id,
        public TenantId $tenantId,
        public array $lines,
    ) {
        if ($id === '') {
            throw new \InvalidArgumentException('Order ID must not be empty.');
        }
        if ($lines === []) {
            throw new \InvalidArgumentException('Order must contain at least one line.');
        }

        $currency = null;
        $minorUnits = 0;
        foreach ($lines as $line) {
            if (!$line instanceof CommercialSnapshotLine) {
                throw new \InvalidArgumentException('Order lines must be commercial snapshot lines.');
            }
            $lineCurrency = $line->unitPrice->currency;
            $currency ??= $lineCurrency;
            if ($currency !== $lineCurrency) {
                throw new \InvalidArgumentException('Order lines must use one currency.');
            }
            if ($line->lineTotal->minorUnits > PHP_INT_MAX - $minorUnits) {
                throw new \OverflowException('Order total exceeds the supported integer range.');
            }
            $minorUnits += $line->lineTotal->minorUnits;
        }

        $this->total = new Money($minorUnits, $currency);
    }
}
