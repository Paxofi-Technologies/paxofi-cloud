<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Contracts;

use PaxofiCloud\Application\Payments\AttemptToStart;
use PaxofiCloud\Application\Payments\AttemptToVerify;
use PaxofiCloud\Application\Payments\GatewayVerification;
use PaxofiCloud\Application\Payments\NewPaymentAttempt;
use PaxofiCloud\Application\Payments\PayableInvoice;
use PaxofiCloud\Domain\Payments\Gateway;
use PaxofiCloud\Domain\Payments\PaymentAttemptState;

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

    /**
     * initiated → pending with the gateway's checkout URL; its first gateway
     * check is due at $firstCheckAt (PY-11). False when the attempt was no
     * longer initiated.
     */
    public function markCheckoutReady(string $attemptId, string $checkoutUrl, \DateTimeImmutable $now, \DateTimeImmutable $firstCheckAt): bool;

    /** initiated → failed with a fixed reason code. False when the attempt was no longer initiated. */
    public function markFailed(string $attemptId, string $reason, \DateTimeImmutable $now): bool;

    /**
     * Pending or unknown attempts whose next check is due, oldest first.
     *
     * @return list<AttemptToVerify>
     */
    public function dueForCheck(\DateTimeImmutable $now, int $limit): array;

    /**
     * Counts one check and sets the next (PY-11); `unknown` moves a pending
     * attempt to unknown (the gateway could not answer). Conditional on the
     * check count read, so a check is never counted twice.
     */
    public function rescheduled(string $attemptId, int $checksSeen, \DateTimeImmutable $nextCheckAt, bool $unknown, \DateTimeImmutable $now): bool;

    /** pending/unknown → failed or abandoned after the last scheduled check. */
    public function close(string $attemptId, int $checksSeen, PaymentAttemptState $final, \DateTimeImmutable $now): bool;

    /**
     * PY-9, one transaction: the attempt becomes succeeded (only from
     * initiated, pending or unknown), with the gateway transaction, amount,
     * fee and time. If its invoice is still unpaid, the invoice and order
     * become paid and the payment is posted against the receivable;
     * otherwise the money goes to the customer's credit (PY-12). The fee is
     * posted when the gateway reported one in the same currency.
     *
     * @return 'applied'|'credited'|null null when the attempt was already settled
     */
    public function recordSuccess(string $attemptId, GatewayVerification $verification, \DateTimeImmutable $now): ?string;

    /**
     * PY-8: the gateway collected a different amount or currency. The attempt
     * becomes amount_mismatch and the money goes to unallocated receipts; the
     * invoice is untouched. False when the attempt was already settled.
     */
    public function recordMismatch(string $attemptId, GatewayVerification $verification, \DateTimeImmutable $now): bool;
}
