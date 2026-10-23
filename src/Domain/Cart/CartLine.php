<?php

declare(strict_types=1);

namespace PaxofiCloud\Domain\Cart;

use PaxofiCloud\Domain\Catalogue\ProductId;

final readonly class CartLine
{
    public function __construct(
        public ProductId $productId,
        public int $quantity,
    ) {
        if ($quantity < 1) {
            throw new \InvalidArgumentException('Cart line quantity must be at least 1.');
        }
    }
}
