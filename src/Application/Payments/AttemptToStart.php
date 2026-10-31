<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Payments;

use PaxofiCloud\Domain\Money\Money;
use PaxofiCloud\Domain\Payments\Gateway;
use PaxofiCloud\Domain\Payments\PaymentReference;

/**
 * A claimed attempt with what the gateway call needs; the payer email is read
 * at claim time, not stored on the attempt (PY-19). `reclaimed` is true when
 * an earlier claim was never finished (the worker stopped mid-call), so the
 * gateway may already know this reference.
 */
final readonly class AttemptToStart
{
    public function __construct(
        public string $id,
        public string $accountId,
        public PaymentReference $reference,
        public Gateway $gateway,
        public Money $amount,
        public string $invoiceNumber,
        public string $payerEmail,
        public \DateTimeImmutable $createdAt,
        public bool $reclaimed = false,
    ) {
    }
}
