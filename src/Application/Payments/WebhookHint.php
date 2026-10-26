<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Payments;

use PaxofiCloud\Domain\Payments\Gateway;
use PaxofiCloud\Domain\Payments\PaymentReference;

/** A genuine webhook naming one of our references. It is a reason to call verify(), never proof of payment (PY-7). */
final readonly class WebhookHint
{
    public function __construct(public Gateway $gateway, public PaymentReference $reference, public string $event)
    {
        if (preg_match('/^[a-z]+(\.[a-z_]+){1,2}$/D', $event) !== 1) {
            throw new \InvalidArgumentException('Webhook event names are plain dotted words.');
        }
    }
}
