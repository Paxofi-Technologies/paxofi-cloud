<?php

declare(strict_types=1);

namespace PaxofiCloud\Domain\Cart;

use PaxofiCloud\Domain\Tenant\TenantId;

final readonly class Cart
{
    /** @param list<CartLine> $lines */
    public function __construct(
        public TenantId $tenantId,
        public array $lines,
    ) {
        if ($lines === []) {
            throw new \InvalidArgumentException('Cart must contain at least one line.');
        }

        $productIds = [];
        foreach ($lines as $line) {
            if (!$line instanceof CartLine) {
                throw new \InvalidArgumentException('Cart lines must be CartLine instances.');
            }
            $key = (string) $line->productId;
            if (isset($productIds[$key])) {
                throw new \InvalidArgumentException('A product may appear only once in a cart.');
            }
            $productIds[$key] = true;
        }
    }
}
