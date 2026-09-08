<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\CartOrder;

use PaxofiCloud\Application\CartOrder\CartToOrderService;
use PaxofiCloud\Application\Contracts\AuditRecorder;
use PaxofiCloud\Application\Contracts\CartReader;
use PaxofiCloud\Application\Contracts\CatalogueReader;
use PaxofiCloud\Application\Contracts\OrderWriter;
use PaxofiCloud\Application\Contracts\PricingReader;
use PaxofiCloud\Application\Contracts\RequestContext;
use PaxofiCloud\Application\Contracts\TenantAuthorizer;
use PaxofiCloud\Domain\Cart\Cart;
use PaxofiCloud\Domain\Cart\CartLine;
use PaxofiCloud\Domain\Catalogue\Money;
use PaxofiCloud\Domain\Catalogue\Product;
use PaxofiCloud\Domain\Catalogue\ProductId;
use PaxofiCloud\Domain\Identity\IdentityId;
use PaxofiCloud\Domain\Order\CommercialSnapshotLine;
use PaxofiCloud\Domain\Order\Order;
use PaxofiCloud\Domain\Tenant\TenantId;

spl_autoload_register(static function (string $class): void {
    $prefix = 'PaxofiCloud\\';
    if (!str_starts_with($class, $prefix)) return;
    $path = __DIR__ . '/../../../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) require_once $path;
});

final class CartReaderFake implements CartReader
{
    public function __construct(private ?Cart $cart) {}
    public function getForTenant(string $cartId, RequestContext $context): ?Cart { return $this->cart; }
}

final class CatalogueReaderFake implements CatalogueReader
{
    public function __construct(private ?Product $product) {}
    /** @return list<Product> */
    public function listAvailable(): array { return $this->product === null ? [] : [$this->product]; }
    public function find(ProductId $productId): ?Product { return $this->product; }
}

final class PricingReaderFake implements PricingReader
{
    public function __construct(private ?Money $price) {}
    public function quote(ProductId $productId): ?Money { return $this->price; }
}

final class TenantAuthorizerFake implements TenantAuthorizer
{
    public int $calls = 0;
    public function __construct(private bool $allowed = true) {}
    public function assertCanAccess(TenantId $tenantId, RequestContext $context): void
    {
        $this->calls++;
        if (!$this->allowed) throw new \RuntimeException('Access denied.');
    }
}

final class OrderWriterFake implements OrderWriter
{
    public ?Order $order = null;
    public function create(Order $order, string $idempotencyKey): void { $this->order = $order; }
}

final class AuditRecorderFake implements AuditRecorder
{
    /** @var list<array{string, string, string, array<string, scalar|null>}> */
    public array $records = [];
    public function record(string $action, string $subjectType, string $subjectId, array $attributes = []): void
    { $this->records[] = [$action, $subjectType, $subjectId, $attributes]; }
}

function makeContext(TenantId $tenant): RequestContext
{ return new RequestContext(new IdentityId('identity-1'), $tenant, 'correlation-1'); }

function expectFailure(callable $operation): void
{
    try { $operation(); } catch (\Throwable) { return; }
    throw new \RuntimeException('Expected operation to fail.');
}

$tenant = new TenantId('tenant-1');
$productId = new ProductId('product-1');
$cart = new Cart($tenant, [new CartLine($productId, 2)]);
$product = new Product($productId, 'Compute Instance', true);
$authorizer = new TenantAuthorizerFake();
$writer = new OrderWriterFake();
$audit = new AuditRecorderFake();
$service = new CartToOrderService(
    new CartReaderFake($cart), new CatalogueReaderFake($product), new PricingReaderFake(new Money(1500, 'USD')),
    $authorizer, $writer, $audit,
);

$order = $service->convert('cart-1', makeContext($tenant), 'idem-1');
assert($order->total->minorUnits === 3000);
assert($order->total->currency === 'USD');
assert($order->lines[0]->unitPrice->minorUnits === 1500);
assert($order->lines[0]->lineTotal->minorUnits === 3000);
assert($writer->order === $order);
assert(count($audit->records) === 1);
assert($authorizer->calls === 1);

expectFailure(fn() => (new CartToOrderService(
    new CartReaderFake($cart), new CatalogueReaderFake(new Product($productId, 'Unavailable', false)),
    new PricingReaderFake(new Money(1500, 'USD')), new TenantAuthorizerFake(), new OrderWriterFake(), new AuditRecorderFake(),
))->convert('cart-1', makeContext($tenant), 'idem-2'));

expectFailure(fn() => (new CartToOrderService(
    new CartReaderFake($cart), new CatalogueReaderFake($product), new PricingReaderFake(null),
    new TenantAuthorizerFake(), new OrderWriterFake(), new AuditRecorderFake(),
))->convert('cart-1', makeContext($tenant), 'idem-3'));

expectFailure(fn() => (new CartToOrderService(
    new CartReaderFake($cart), new CatalogueReaderFake($product), new PricingReaderFake(new Money(1500, 'USD')),
    new TenantAuthorizerFake(false), new OrderWriterFake(), new AuditRecorderFake(),
))->convert('cart-1', makeContext($tenant), 'idem-4'));

$usdLine = new CommercialSnapshotLine($productId, 'Compute Instance', 1, new Money(1000, 'USD'));
$eurLine = new CommercialSnapshotLine(new ProductId('product-2'), 'Storage', 1, new Money(1000, 'EUR'));
expectFailure(fn() => new Order('order-1', $tenant, [$usdLine, $eurLine]));

assert($order->lines[0]->productName === 'Compute Instance');
assert($order->lines[0]->unitPrice->minorUnits === 1500);

echo "Cart-to-order invariants passed.\n";
