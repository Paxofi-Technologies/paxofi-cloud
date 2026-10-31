<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Payments;

use PaxofiCloud\Domain\Money\Money;
use PaxofiCloud\Domain\Payments\Gateway;
use PaxofiCloud\Domain\Payments\PaymentReference;

/** A payment attempt as the web tier creates it: state `initiated`, amount copied from the invoice balance (PY-5). */
final readonly class NewPaymentAttempt
{
    public function __construct(
        public string $id,
        public PaymentReference $reference,
        public string $accountId,
        public string $invoiceId,
        public string $initiatedBy,
        public Gateway $gateway,
        public Money $amount,
        public string $idempotencyKey,
        public string $requestHash,
        public \DateTimeImmutable $createdAt,
    ) {
        if ($amount->minorUnits <= 0) {
            throw new \InvalidArgumentException('A payment is for a positive amount.');
        }
    }
}
