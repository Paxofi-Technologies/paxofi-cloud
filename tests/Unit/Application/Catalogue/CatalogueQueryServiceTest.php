<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Application\Catalogue;

use PaxofiCloud\Application\Catalogue\CatalogueQueryService;
use PaxofiCloud\Application\Contracts\CatalogueReader;
use PaxofiCloud\Application\Contracts\PricingReader;
use PaxofiCloud\Application\Contracts\RequestContext;
use PaxofiCloud\Application\Contracts\TenantAuthorizer;
use PaxofiCloud\Application\Identity\UnauthorizedTenantAccess;
use PaxofiCloud\Domain\Catalogue\Money;
use PaxofiCloud\Domain\Catalogue\Product;
use PaxofiCloud\Domain\Catalogue\ProductId;
use PaxofiCloud\Domain\Identity\IdentityId;
use PaxofiCloud\Domain\Tenant\TenantId;
use PHPUnit\Framework\TestCase;

final class CatalogueQueryServiceTest extends TestCase
{
    private TenantId $tenantId;
    private RequestContext $context;

    protected function setUp(): void
    {
        $this->tenantId = new TenantId('tenant-1');
        $this->context = new RequestContext(new IdentityId('identity-1'), $this->tenantId, 'corr-1');
    }

    public function testReturnsAvailableProductWithMinorUnitPrice(): void
    {
        $productId = new ProductId('product-1');
        $service = $this->service([new Product($productId, 'Cloud VPS', true)], ['product-1' => new Money(2500, 'USD')]);

        $result = $service->productWithPrice($productId, $this->tenantId, $this->context);

        self::assertNotNull($result);
        self::assertSame('Cloud VPS', $result['product']->name);
        self::assertSame(2500, $result['price']->minorUnits);
        self::assertSame('USD', $result['price']->currency);
    }

    public function testReturnsNullForUnavailableProduct(): void
    {
        $productId = new ProductId('product-1');
        $service = $this->service([new Product($productId, 'Cloud VPS', false)], ['product-1' => new Money(2500, 'USD')]);

        self::assertNull($service->productWithPrice($productId, $this->tenantId, $this->context));
    }

    public function testReturnsNullForUnpricedProduct(): void
    {
        $productId = new ProductId('product-1');
        $service = $this->service([new Product($productId, 'Cloud VPS', true)], []);

        self::assertNull($service->productWithPrice($productId, $this->tenantId, $this->context));
    }

    public function testReturnsNullForUnknownProduct(): void
    {
        $service = $this->service([], []);

        self::assertNull($service->productWithPrice(new ProductId('missing'), $this->tenantId, $this->context));
    }

    public function testAuthorizesBeforeReadingCatalogue(): void
    {
        $service = $this->service([new Product(new ProductId('product-1'), 'Cloud VPS', true)], [], denyAll: true);

        $this->expectException(UnauthorizedTenantAccess::class);

        $service->listAvailable($this->tenantId, $this->context);
    }

    /**
     * @param list<Product> $products
     * @param array<string, Money> $prices
     */
    private function service(array $products, array $prices, bool $denyAll = false): CatalogueQueryService
    {
        $catalogue = new class($products) implements CatalogueReader {
            /** @param list<Product> $products */
            public function __construct(private array $products)
            {
            }

            public function listAvailable(): array
            {
                return array_values(array_filter($this->products, static fn (Product $p): bool => $p->available));
            }

            public function find(ProductId $productId): ?Product
            {
                foreach ($this->products as $product) {
                    if ($product->id->value === $productId->value) {
                        return $product;
                    }
                }

                return null;
            }
        };

        $pricing = new class($prices) implements PricingReader {
            /** @param array<string, Money> $prices */
            public function __construct(private array $prices)
            {
            }

            public function quote(ProductId $productId): ?Money
            {
                return $this->prices[$productId->value] ?? null;
            }
        };

        $authorizer = new class($denyAll) implements TenantAuthorizer {
            public function __construct(private bool $denyAll)
            {
            }

            public function assertCanAccess(TenantId $tenantId, RequestContext $context): void
            {
                if ($this->denyAll || $tenantId->value !== $context->tenantId->value) {
                    throw UnauthorizedTenantAccess::for($context->identityId, $tenantId);
                }
            }
        };

        return new CatalogueQueryService($catalogue, $pricing, $authorizer);
    }
}
