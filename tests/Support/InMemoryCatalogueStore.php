<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Support;

use PaxofiCloud\Application\Contracts\CatalogueStore;
use PaxofiCloud\Domain\Catalogue\CatalogueProduct;
use PaxofiCloud\Domain\Catalogue\PlanDefinition;

final class InMemoryCatalogueStore implements CatalogueStore
{
    /** @param list<CatalogueProduct> $products */
    public function __construct(public array $products = [])
    {
    }

    public function products(): array
    {
        return $this->products;
    }

    public function plan(string $slug): ?PlanDefinition
    {
        foreach ($this->products as $product) {
            foreach ($product->plans as $plan) {
                if ($plan->slug === $slug) {
                    return $plan;
                }
            }
        }

        return null;
    }
}
