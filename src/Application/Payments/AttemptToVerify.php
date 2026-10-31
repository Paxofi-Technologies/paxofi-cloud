<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Payments;

use PaxofiCloud\Domain\Money\Money;
use PaxofiCloud\Domain\Payments\Gateway;
use PaxofiCloud\Domain\Payments\PaymentAttemptState;
use PaxofiCloud\Domain\Payments\PaymentReference;

/** An open attempt whose next gateway check is due (PY-11), with what PY-8 compares against. */
final readonly class AttemptToVerify
{
    public function __construct(
        public string $id,
        public string $accountId,
        public PaymentReference $reference,
        public Gateway $gateway,
        public Money $amount,
        public PaymentAttemptState $state,
        public int $checks,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
