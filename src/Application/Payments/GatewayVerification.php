<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Payments;

use PaxofiCloud\Domain\Money\Money;
use PaxofiCloud\Domain\Payments\PaymentReference;

/**
 * The gateway's answer for one reference. The amount and currency are what
 * the gateway says it collected; the caller compares them with the attempt
 * before marking anything paid (PY-8, AMOUNT_MISMATCH). Gateway text such as
 * Paystack's gateway_response is never carried.
 */
final readonly class GatewayVerification
{
    public function __construct(
        public PaymentReference $reference,
        public PaymentOutcome $outcome,
        public ?Money $amount = null,
        public ?string $gatewayTransactionId = null,
        public ?\DateTimeImmutable $paidAt = null,
        public ?Money $fee = null,
    ) {
        if ($outcome === PaymentOutcome::Succeeded && ($amount === null || $gatewayTransactionId === null)) {
            throw new \InvalidArgumentException('A successful payment names its amount and the gateway transaction.');
        }
        if ($gatewayTransactionId !== null && preg_match('/^[0-9]{1,20}$/D', $gatewayTransactionId) !== 1) {
            throw new \InvalidArgumentException('Gateway transaction IDs are numeric.');
        }
    }
}
