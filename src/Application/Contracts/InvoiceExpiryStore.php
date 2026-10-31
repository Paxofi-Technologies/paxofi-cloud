<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Contracts;

/**
 * Unpaid new-order invoices past their expiry (THREAT-MODEL-PAYMENTS.md PY-16,
 * CRT-006, ORD-006). An invoice with a payment attempt still open
 * (initiated, pending or unknown) is never expired: the payments worker's
 * scheduled checks settle the attempt first, so a payment that is on its way
 * is never thrown away.
 */
interface InvoiceExpiryStore
{
    /**
     * Unpaid invoices whose expiry time has passed and that have no open attempt, oldest first.
     *
     * @return list<array{invoice_id: string, order_id: string, account_id: string}>
     */
    public function dueForExpiry(\DateTimeImmutable $now, int $limit): array;

    /**
     * One transaction: the invoice becomes cancelled (only while it is still
     * unpaid, past expiry and without an open attempt), the order becomes
     * expired (only from pending_payment), and the invoice's issue entry is
     * reversed in the ledger. False when another path got there first.
     */
    public function expire(string $invoiceId, \DateTimeImmutable $now): bool;
}
