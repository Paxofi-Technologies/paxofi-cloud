<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Contracts;

use PaxofiCloud\Application\Checkout\NewOrder;

/**
 * Orders and their first invoice (commerce threat model CD-24, CD-26).
 * Every read is scoped by account in the query (C-21, C-46).
 *
 * @phpstan-type OrderLineRow array{domain: string, years: int, price: int, tax: int, total: int, renewal_price: int}
 * @phpstan-type InvoiceLineRow array{description: string, amount: int, tax: int, total: int}
 * @phpstan-type InvoiceRow array{number: string, status: string, currency: string, subtotal: int, tax: int, total: int, tax_name: string, tax_rate_bp: int, tax_country: string, issued_at: string, due_at: string, expires_at: string, order_id: string, order_number: string, bill_to: array{name: string, company: ?string, line1: string, line2: ?string, city: string, region: string, postcode: ?string, country: string, phone: string, tin: ?string}, lines: list<InvoiceLineRow>}
 * @phpstan-type OrderRow array{id: string, number: string, status: string, currency: string, subtotal: int, tax: int, total: int, tax_name: string, tax_rate_bp: int, placed_at: string, expires_at: string, invoice_number: string, invoice_status: string, lines: list<OrderLineRow>}
 */
interface OrderStore
{
    /** @return array{id: string, request_hash: string}|null */
    public function findByIdempotencyKey(string $accountId, string $key): ?array;

    /**
     * Writes the order, its lines, the invoice and its lines in one transaction,
     * allocating gap-free numbers and locking the account's billing currency.
     *
     * @return array{order_number: string, invoice_number: string}
     * @throws DuplicateIdempotencyKey when a concurrent request already used the key
     */
    public function place(NewOrder $order): array;

    /** @return OrderRow|null */
    public function find(string $accountId, string $orderId): ?array;

    /**
     * The invoice issued for one of the account's orders, with its billing snapshot (INV-001).
     *
     * @return InvoiceRow|null
     */
    public function invoiceForOrder(string $accountId, string $orderId): ?array;

    /** @return list<OrderRow> newest first */
    public function forAccount(string $accountId, int $limit, int $offset): array;
}
