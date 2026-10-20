<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Contracts;

use PaxofiCloud\Domain\Catalogue\CatalogueProduct;
use PaxofiCloud\Domain\Catalogue\PlanDefinition;

/**
 * Customer-facing catalogue data. Implementations never read the provider
 * mapping or cost tables (CAT-003, PRC-004; commerce threat model CD-5).
 */
interface CatalogueStore
{
    /** @return list<CatalogueProduct> every product and plan, in display order, in any state */
    public function products(): array;

    public function plan(string $slug): ?PlanDefinition;
}
