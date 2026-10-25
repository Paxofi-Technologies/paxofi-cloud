<?php

declare(strict_types=1);

namespace PaxofiCloud\Domain\Orders;

/** The invoice state machine (SRS §5.3, INV-003; commerce threat model CD-25). */
enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Unpaid = 'unpaid';
    case Paid = 'paid';
    case PartiallyPaid = 'partially_paid';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';

    /** @return list<self> */
    public function next(): array
    {
        return match ($this) {
            self::Draft => [self::Unpaid],
            self::Unpaid => [self::Paid, self::PartiallyPaid, self::Cancelled],
            self::PartiallyPaid => [self::Paid],
            self::Paid => [self::Refunded],
            self::Cancelled, self::Refunded => [],
        };
    }

    /** @throws InvalidTransition */
    public function to(self $next): self
    {
        if (!in_array($next, $this->next(), true)) {
            throw new InvalidTransition('invoice', $this->value, $next->value);
        }

        return $next;
    }
}
