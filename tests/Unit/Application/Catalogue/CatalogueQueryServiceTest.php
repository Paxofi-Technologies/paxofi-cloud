<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Application\Catalogue;

use PaxofiCloud\Application\Catalogue\CatalogueQueryService;
use PaxofiCloud\Application\Contracts\CatalogueReader;
use PaxofiCloud\Application\Contracts\PricingReader;
use PaxofiCloud\Application\Contracts\RequestContext;
use PaxofiCloud\Application\Contracts\TenantAuthorizer;
use PaxofiCloud\Domain\Catalogue\Money;
use PaxofiCloud\Domain\Catalogue\Product;
use PaxofiCloud\Domain\Catalogue\ProductId;
use PaxofiCloud\Domain\Identity\IdentityId;
use PaxofiCloud\Domain\Tenant\TenantId;

final class CatalogueQueryServiceTest
{
    public static function run(): void
    {
        $productId = new ProductId('product-1');
        $tenantId = new TenantId('tenant-1');
        $context = new RequestContext(new IdentityId('identity-1'), $tenantId, 'corr-1');
        $product = new Product($productId, 'Cloud VPS', true);

        $catalogue = new class($product) implements CatalogueReader {
            public function __construct(private Product $product) {}
            public function listAvailable(): array { return [$this->product]; }
            public function find(ProductId $productId): ?Product { return $productId->value === $this->product->id->value ? $this->product : null; }
        };
        $pricing = new class($productId) implements PricingReader {
            public function __construct(private ProductId $productId) {}
            public function quote(ProductId $productId): ?Money { return $productId->value === $this->productId->value ? new Money(2500, 'USD') : null; }
        };
        $authorizer = new class implements TenantAuthorizer {
            public function assertCanAccess(TenantId $tenantId, RequestContext $context): void
            {
                if ($tenantId->value !== $context->tenantId->value) {
                    throw new \RuntimeException('Unexpected tenant authorization request.');
                }
            }
        };

        $service = new CatalogueQueryService($catalogue, $pricing, $authorizer);
        $result = $service->productWithPrice($productId, $tenantId, $context);

        if ($result === null || $result['price']->minorUnits !== 2500 || $result['price']->currency !== 'USD') {
            throw new \RuntimeException('Catalogue pricing result is invalid.');
        }
    }
}
