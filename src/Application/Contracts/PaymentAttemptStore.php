<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Contracts;

use PaxofiCloud\Application\Payments\AttemptToStart;
use PaxofiCloud\Application\Payments\NewPaymentAttempt;
use PaxofiCloud\Application\Payments\PayableInvoice;
use PaxofiCloud\Domain\Payments\Gateway;

/**
 * Payment attempts (THREAT-MODEL-PAYMENTS.md PY-5, PY-6). Every customer
 * read is scoped by account in the query; state changes are conditional on
 * the current state, so a second worker or a replay changes nothing.
 *
 * @phpstan-type AttemptRow array{id: string, reference: string, gateway: string, state: string, amount: int, currency: string, invoice_number: string, order_id: string, checkout_url: ?string, failure_reason: ?string, created_at: string}
 */
interface PaymentAttemptStore
{
    public const int CLAIM_TIMEOUT_SECONDS = 300;

    /** The invoice for one of the account's orders, as far as paying it is concerned. */
    public function payableInvoice(string $accountId, string $orderId): ?PayableInvoice;

    /**
     * Gateways with a stored credential for this environment ('dev', 'staging' or 'prod').
     *
     * @return list<Gateway>
     */
    public function connectedGateways(string $environment): array;

    /** Attempts on the invoice that are still open (initiated, pending, unknown). */
    public function openAttempts(string $invoiceId): int;

    /** @return array{id: string, request_hash: string}|null */
    public function findByIdempotencyKey(string $accountId, string $key): ?array;

    /** @throws DuplicateIdempotencyKey when a concurrent request already used the key */
    public function create(NewPaymentAttempt $attempt): void;

    /** @return AttemptRow|null */
    public function find(string $accountId, string $attemptId): ?array;

    /**
     * Claims the oldest initiated attempt nobody has claimed, with what the
     * gateway call needs. A claim is a conditional update, so two workers
     * never both start the same checkout. A claim older than CLAIM_TIMEOUT
     * seconds that never finished is handed out again as `reclaimed`.
     */
    public function claimNext(\DateTimeImmutable $now): ?AttemptToStart;

    /** initiated → pending with the gateway's checkout URL. False when the attempt was no longer initiated. */
    public function markCheckoutReady(string $attemptId, string $checkoutUrl, \DateTimeImmutable $now): bool;

    /** initiated → failed with a fixed reason code. False when the attempt was no longer initiated. */
    public function markFailed(string $attemptId, string $reason, \DateTimeImmutable $now): bool;
}
