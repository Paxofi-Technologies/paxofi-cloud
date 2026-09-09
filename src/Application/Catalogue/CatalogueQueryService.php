<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Catalogue;

use PaxofiCloud\Application\Contracts\CatalogueReader;
use PaxofiCloud\Application\Contracts\PricingReader;
use PaxofiCloud\Application\Contracts\RequestContext;
use PaxofiCloud\Application\Contracts\TenantAuthorizer;
use PaxofiCloud\Domain\Catalogue\Money;
use PaxofiCloud\Domain\Catalogue\Product;
use PaxofiCloud\Domain\Catalogue\ProductId;
use PaxofiCloud\Domain\Tenant\TenantId;

final class CatalogueQueryService
{
    public function __construct(
        private CatalogueReader $catalogue,
        private PricingReader $pricing,
        private TenantAuthorizer $authorizer,
    ) {
    }

    /** @return list<Product> */
    public function listAvailable(TenantId $tenantId, RequestContext $context): array
    {
        $this->authorizer->assertCanAccess($tenantId, $context);

        return $this->catalogue->listAvailable();
    }

    /** @return array{product: Product, price: Money}|null */
    public function productWithPrice(
        ProductId $productId,
        TenantId $tenantId,
        RequestContext $context,
    ): ?array {
        $this->authorizer->assertCanAccess($tenantId, $context);

        $product = $this->catalogue->find($productId);
        $price = $product === null ? null : $this->pricing->quote($productId);

        if ($product === null || $price === null || !$product->available) {
            return null;
        }

        return ['product' => $product, 'price' => $price];
    }
}
