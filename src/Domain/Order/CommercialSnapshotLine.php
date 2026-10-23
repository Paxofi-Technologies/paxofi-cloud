<?php

declare(strict_types=1);

namespace PaxofiCloud\Domain\Order;

use PaxofiCloud\Domain\Catalogue\Money;
use PaxofiCloud\Domain\Catalogue\ProductId;

final readonly class CommercialSnapshotLine
{
    public Money $lineTotal;

    public function __construct(
        public ProductId $productId,
        public string $productName,
        public int $quantity,
        public Money $unitPrice,
    ) {
        if ($productName === '') {
            throw new \InvalidArgumentException('Snapshot product name cannot be empty.');
        }
        if ($quantity < 1) {
            throw new \InvalidArgumentException('Snapshot quantity must be at least 1.');
        }
        if ($unitPrice->minorUnits < 0) {
            throw new \InvalidArgumentException('Snapshot unit price cannot be negative.');
        }
        if ($unitPrice->minorUnits !== 0 && $quantity > intdiv(PHP_INT_MAX, $unitPrice->minorUnits)) {
            throw new \OverflowException('Snapshot line total exceeds the supported integer range.');
        }

        $this->lineTotal = new Money($unitPrice->minorUnits * $quantity, $unitPrice->currency);
    }
}
