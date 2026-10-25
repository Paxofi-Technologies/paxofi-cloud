<?php

declare(strict_types=1);

namespace PaxofiCloud\Domain\Orders;

/**
 * The order state machine (SRS §5.1, ORD-001; commerce threat model CD-25).
 * A transition not listed here throws; nothing else may change an order's state.
 */
enum OrderStatus: string
{
    case PendingPayment = 'pending_payment';
    case Paid = 'paid';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case FraudReview = 'fraud_review';
    case Fulfilling = 'fulfilling';
    case Fulfilled = 'fulfilled';
    case PartiallyFulfilled = 'partially_fulfilled';
    case Failed = 'failed';

    /** @return list<self> */
    public function next(): array
    {
        return match ($this) {
            self::PendingPayment => [self::Paid, self::Cancelled, self::Expired],
            self::Paid => [self::FraudReview, self::Fulfilling],
            self::FraudReview => [self::Paid, self::Cancelled],
            self::Fulfilling => [self::Fulfilled, self::PartiallyFulfilled, self::Failed],
            self::Cancelled, self::Expired, self::Fulfilled, self::PartiallyFulfilled, self::Failed => [],
        };
    }

    /** @throws InvalidTransition */
    public function to(self $next): self
    {
        if (!in_array($next, $this->next(), true)) {
            throw new InvalidTransition('order', $this->value, $next->value);
        }

        return $next;
    }
}
