<?php

declare(strict_types=1);

namespace PaxofiCloud\Domain\Payments;

use PaxofiCloud\Domain\Orders\InvalidTransition;

/**
 * The payment attempt state machine (SRS §5.2, PAY-004; THREAT-MODEL-PAYMENTS.md PY-5).
 * Only the payments worker moves an attempt past `initiated`; nothing here
 * marks an invoice paid (that is PY-9, after verify()).
 */
enum PaymentAttemptState: string
{
    case Initiated = 'initiated';
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Abandoned = 'abandoned';
    case Unknown = 'unknown';
    case AmountMismatch = 'amount_mismatch';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';

    /** @return list<self> */
    public function next(): array
    {
        return match ($this) {
            self::Initiated => [self::Pending, self::Failed, self::Succeeded, self::Unknown, self::AmountMismatch],
            self::Pending, self::Unknown => [self::Succeeded, self::Failed, self::Abandoned, self::Unknown, self::AmountMismatch],
            self::Succeeded => [self::Refunded, self::PartiallyRefunded],
            self::PartiallyRefunded => [self::Refunded],
            self::Failed, self::Abandoned, self::AmountMismatch, self::Refunded => [],
        };
    }

    /** @throws InvalidTransition */
    public function to(self $next): self
    {
        if (!in_array($next, $this->next(), true)) {
            throw new InvalidTransition('payment attempt', $this->value, $next->value);
        }

        return $next;
    }

    /** Still waiting on the customer or the gateway; a new attempt for the same invoice may be refused while one is open. */
    public function isOpen(): bool
    {
        return in_array($this, [self::Initiated, self::Pending, self::Unknown], true);
    }
}
