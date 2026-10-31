<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Payments;

use PaxofiCloud\Domain\Money\Money;
use PaxofiCloud\Domain\Orders\InvoiceStatus;

/** What paying an invoice needs to know about it: its state, balance and expiry (PY-5, PY-16). */
final readonly class PayableInvoice
{
    public function __construct(
        public string $invoiceId,
        public string $number,
        public InvoiceStatus $status,
        public Money $balance,
        public \DateTimeImmutable $expiresAt,
    ) {
    }
}
