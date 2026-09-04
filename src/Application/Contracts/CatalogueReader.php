<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Contracts;

use PaxofiCloud\Domain\Catalogue\Product;
use PaxofiCloud\Domain\Catalogue\ProductId;

interface CatalogueReader
{
    /** @return list<Product> */
    public function listAvailable(): array;

    public function find(ProductId $productId): ?Product;
}
