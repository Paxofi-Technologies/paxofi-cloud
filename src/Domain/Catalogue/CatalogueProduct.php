<?php

declare(strict_types=1);

namespace PaxofiCloud\Domain\Catalogue;

/** A product line (e.g. "Cloud VPS") with its plans, as the catalogue sells it. */
final readonly class CatalogueProduct
{
    /** @param list<PlanDefinition> $plans */
    public function __construct(
        public string $id,
        public string $slug,
        public ProductKind $kind,
        public string $name,
        public string $description,
        public array $plans,
    ) {
    }
}
