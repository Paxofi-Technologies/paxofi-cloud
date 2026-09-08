<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\CartOrder;

use PaxofiCloud\Application\Contracts\AuditRecorder;
use PaxofiCloud\Application\Contracts\CartReader;
use PaxofiCloud\Application\Contracts\CatalogueReader;
use PaxofiCloud\Application\Contracts\OrderWriter;
use PaxofiCloud\Application\Contracts\PricingReader;
use PaxofiCloud\Application\Contracts\RequestContext;
use PaxofiCloud\Application\Contracts\TenantAuthorizer;
use PaxofiCloud\Domain\Order\CommercialSnapshotLine;
use PaxofiCloud\Domain\Order\Order;

final class CartToOrderService
{
    public function __construct(
        private CartReader $carts,
        private CatalogueReader $catalogue,
        private PricingReader $pricing,
        private TenantAuthorizer $authorizer,
        private OrderWriter $orders,
        private AuditRecorder $audit,
    ) {
    }

    public function convert(string $cartId, RequestContext $context, string $idempotencyKey): Order
    {
        if ($cartId === '') {
            throw new \InvalidArgumentException('Cart ID must not be empty.');
        }
        if ($idempotencyKey === '') {
            throw new \InvalidArgumentException('Idempotency key must not be empty.');
        }

        $cart = $this->carts->getForTenant($cartId, $context);
        if ($cart === null) {
            throw new \RuntimeException('Cart was not found.');
        }

        $this->authorizer->assertCanAccess($cart->tenantId, $context);

        $snapshotLines = [];
        foreach ($cart->lines as $line) {
            $product = $this->catalogue->find($line->productId);
            if ($product === null || !$product->available) {
                throw new \RuntimeException('Cart contains an unavailable product.');
            }

            $price = $this->pricing->quote($line->productId);
            if ($price === null || $price->minorUnits < 0) {
                throw new \RuntimeException('Cart contains a product without a valid current price.');
            }

            $snapshotLines[] = new CommercialSnapshotLine(
                productId: $product->id,
                productName: $product->name,
                quantity: $line->quantity,
                unitPrice: $price,
            );
        }

        $order = new Order(
            id: bin2hex(random_bytes(16)),
            tenantId: $cart->tenantId,
            lines: $snapshotLines,
        );

        $this->orders->create($order, $idempotencyKey);
        $this->audit->record(
            action: 'order.created_from_cart',
            subjectType: 'order',
            subjectId: $order->id,
            attributes: [
                'tenant_id' => (string) $order->tenantId,
                'cart_id' => $cartId,
                'idempotency_key' => $idempotencyKey,
                'total_minor_units' => $order->total->minorUnits,
                'currency' => $order->total->currency,
            ],
        );

        return $order;
    }
}
