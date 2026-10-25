<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Support;

use PaxofiCloud\Application\Checkout\NewOrder;
use PaxofiCloud\Application\Checkout\NewOrderLine;
use PaxofiCloud\Application\Contracts\DuplicateIdempotencyKey;
use PaxofiCloud\Application\Contracts\OrderStore;
use PaxofiCloud\Domain\Orders\DocumentNumber;

/** Orders kept in memory with the same numbering and scoping rules as MySQL. */
final class InMemoryOrderStore implements OrderStore
{
    /** @var array<string, NewOrder> */
    public array $orders = [];
    /** @var array<string, array{order_number: string, invoice_number: string}> */
    public array $numbers = [];
    public ?InMemoryBillingStore $billing = null;

    public function findByIdempotencyKey(string $accountId, string $key): ?array
    {
        foreach ($this->orders as $order) {
            if ($order->accountId === $accountId && $order->idempotencyKey === $key) {
                return ['id' => $order->orderId, 'request_hash' => $order->requestHash];
            }
        }

        return null;
    }

    public function place(NewOrder $order): array
    {
        if ($this->findByIdempotencyKey($order->accountId, $order->idempotencyKey) !== null) {
            throw new DuplicateIdempotencyKey('duplicate');
        }
        $n = count($this->orders) + 1;
        $this->orders[$order->orderId] = $order;
        $this->numbers[$order->orderId] = ['order_number' => DocumentNumber::order(2026, $n), 'invoice_number' => DocumentNumber::invoice(2026, $n)];
        if ($this->billing !== null && $this->billing->find($order->accountId)?->currencyLockedAt === null) {
            $this->billing->lock($order->accountId, $order->placedAt);
        }

        return $this->numbers[$order->orderId];
    }

    public function find(string $accountId, string $orderId): ?array
    {
        $order = $this->orders[$orderId] ?? null;

        return $order === null || $order->accountId !== $accountId ? null : $this->row($order);
    }

    public function forAccount(string $accountId, int $limit, int $offset): array
    {
        $rows = array_values(array_map($this->row(...), array_filter($this->orders, static fn (NewOrder $o): bool => $o->accountId === $accountId)));

        return array_slice(array_reverse($rows), $offset, $limit);
    }

    /** @return array{id: string, number: string, status: string, currency: string, subtotal: int, tax: int, total: int, tax_name: string, tax_rate_bp: int, placed_at: string, expires_at: string, invoice_number: string, invoice_status: string, lines: list<array{domain: string, years: int, price: int, tax: int, total: int, renewal_price: int}>} */
    private function row(NewOrder $o): array
    {
        return [
            'id' => $o->orderId,
            'number' => $this->numbers[$o->orderId]['order_number'],
            'status' => 'pending_payment',
            'currency' => $o->currency->value,
            'subtotal' => $o->subtotalMinor(),
            'tax' => $o->taxMinor(),
            'total' => $o->subtotalMinor() + $o->taxMinor(),
            'tax_name' => $o->tax->name,
            'tax_rate_bp' => $o->tax->rate->value,
            'placed_at' => $o->placedAt->format(\DATE_ATOM),
            'expires_at' => $o->expiresAt->format(\DATE_ATOM),
            'invoice_number' => $this->numbers[$o->orderId]['invoice_number'],
            'invoice_status' => 'unpaid',
            'lines' => array_map(static fn (NewOrderLine $l): array => ['domain' => $l->domain, 'years' => 1, 'price' => $l->priceMinor, 'tax' => $l->taxMinor, 'total' => $l->totalMinor(), 'renewal_price' => $l->renewalPriceMinor], $o->lines),
        ];
    }
}
