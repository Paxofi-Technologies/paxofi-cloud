<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Contracts;

use PaxofiCloud\Domain\Catalogue\Money;
use PaxofiCloud\Domain\Catalogue\ProductId;

interface PricingReader
{
    public function quote(ProductId $productId): ?Money;
}
